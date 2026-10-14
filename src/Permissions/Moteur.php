<?php

declare(strict_types=1);

namespace Ged\Permissions;

use Ged\Config;
use Ged\Workflows\Circuits;
use Ged\Workflows\Moteur as MoteurWorkflows;
use PDO;
use RuntimeException;

/**
 * Moteur des permissions dynamiques (famille M-Files « permissions dynamiques »).
 *
 * Il évalue une règle nommée (`Ged\Permissions\Regles`) contre trois faits d'un document — sa CLASSE,
 * une VALEUR DE MÉTADONNÉE et son ÉTAT de circuit — et le contexte d'un lecteur (compte, groupes,
 * rôles, administrateur) :
 *  - `decision()` : voir / modifier pour UN document (évaluation pure, lisible par les oracles) ;
 *  - `clauseSql()` : fragment SQL qui RETIRE de la liste et de la recherche les documents non visibles
 *    (mêmes conditions, sémantique identique à la décision) ;
 *  - `etat()`, `circuits()`, `etats()`, `transitions()`, `transitionner()` : axe « droits selon l'état »,
 *    délégués au moteur `Ged\Workflows` existant — jamais une seconde machine à états.
 *
 * Sémantique stricte : une règle ne fait que RESTREINDRE. Elle n'accorde jamais un accès que l'ACL de
 * dossier ou la garde centrale auraient refusé ; l'administrateur (`*`) voit toujours tout. Un document
 * hors conditions est intact.
 */
final class Moteur
{
    private readonly Regles $regles;
    private ?bool $etatDisponible = null;

    public function __construct(
        private readonly Config $config,
        private readonly ?PDO $pdo = null,
    ) {
        $this->regles = new Regles($config);
    }

    public function regles(): Regles
    {
        return $this->regles;
    }

    /**
     * Contexte d'un lecteur : ses groupes et rôles sont résolus EN BASE (jamais depuis le navigateur),
     * pour que la même décision serve la liste, la recherche et l'écran.
     *
     * @return array{compte:?int, administrateur:bool, groupes:list<int>, roles:list<string>}
     */
    public function contextePourCompte(?int $compte, bool $administrateur = false): array
    {
        $contexte = ['compte' => $compte, 'administrateur' => $administrateur, 'groupes' => [], 'roles' => []];
        if ($administrateur || $compte === null || $compte < 1 || $this->pdo === null) {
            return $contexte;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT groupe_id FROM securite_groupes_comptes WHERE compte_id = ?');
            $stmt->execute([$compte]);
            $contexte['groupes'] = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

            $stmt = $this->pdo->prepare('SELECT role FROM comptes WHERE id = ?');
            $stmt->execute([$compte]);
            $role = $stmt->fetchColumn();

            $stmt = $this->pdo->prepare('SELECT g.role_code FROM securite_groupes g'
                . ' INNER JOIN securite_groupes_comptes gc ON gc.groupe_id = g.id WHERE gc.compte_id = ?');
            $stmt->execute([$compte]);
            $roles = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($role !== false && $role !== null) {
                $roles[] = (string) $role;
            }
            $contexte['roles'] = array_values(array_unique($roles));
        } catch (\PDOException) {
            // Schéma de sécurité absent : aucun groupe ni rôle connu, la règle refusera par prudence.
        }

        return $contexte;
    }

    /**
     * Décision pour UN document. Une règle qui retient le document ET protège une capacité n'ouvre celle-ci
     * qu'à ses cibles ; toute règle qui exclut le lecteur l'emporte (le refus gagne).
     *
     * @param array<string, mixed> $doc ['classe' => ?string, 'champs' => array, 'etat' => ?string]
     * @param array<string, mixed> $contexte ['compte' => ?int, 'administrateur' => bool, 'groupes' => list<int>, 'roles' => list<string>]
     * @return array{voir:bool, modifier:bool, appliquees:list<string>}
     */
    public function decision(array $doc, array $contexte): array
    {
        if (!empty($contexte['administrateur'])) {
            return ['voir' => true, 'modifier' => true, 'appliquees' => []];
        }
        $voir = true;
        $modifier = true;
        $appliquees = [];
        foreach ($this->regles->actives() as $regle) {
            if (!$this->correspond((array) $regle['conditions'], $doc)) {
                continue;
            }
            foreach (['voir', 'modifier'] as $capacite) {
                if (empty($regle[$capacite]) || $this->cibleContient((array) $regle['cibles'], $contexte)) {
                    continue;
                }
                if ($capacite === 'voir') {
                    $voir = false;
                } else {
                    $modifier = false;
                }
                $appliquees[] = (string) $regle['nom'];
            }
        }

        return ['voir' => $voir, 'modifier' => $modifier, 'appliquees' => array_values(array_unique($appliquees))];
    }

    /**
     * Fragment SQL qui exclut de la lecture les documents que les règles protègent à l'encontre de ce
     * lecteur. Il s'ajoute APRÈS la clause ACL de dossier (jamais à sa place) : la sécurité existante
     * reste la première barrière. Null signifie « aucune restriction » (aucune règle active, ou lecteur
     * administrateur).
     *
     * @param array<string, mixed> $contexte
     * @return array{0:?string, 1:list<mixed>}
     */
    public function clauseSql(string $alias, array $contexte): array
    {
        if (!empty($contexte['administrateur'])) {
            return [null, []];
        }
        $clauses = [];
        $params = [];
        foreach ($this->regles->actives() as $regle) {
            if (empty($regle['voir']) || $this->cibleContient((array) $regle['cibles'], $contexte)) {
                continue;
            }
            [$condition, $valeurs] = $this->conditionsSql($alias, (array) $regle['conditions']);
            $clauses[] = 'NOT (' . $condition . ')';
            $params = [...$params, ...$valeurs];
        }
        if ($clauses === []) {
            return [null, []];
        }

        return ['(' . implode(' AND ', $clauses) . ')', $params];
    }

    /**
     * Le document retenu par cette règle ? (les trois conditions se cumulent ; une condition vide ne
     * contraint rien).
     *
     * @param array<string, mixed> $conditions
     * @param array<string, mixed> $doc
     */
    private function correspond(array $conditions, array $doc): bool
    {
        $classe = $conditions['classe'] ?? null;
        if (is_string($classe) && $classe !== '' && (string) ($doc['classe'] ?? '') !== $classe) {
            return false;
        }
        $etat = $conditions['etat'] ?? null;
        if (is_string($etat) && $etat !== '' && (string) ($doc['etat'] ?? '') !== $etat) {
            return false;
        }
        $champ = $conditions['champ'] ?? null;
        if (is_string($champ) && $champ !== '') {
            $champs = (array) ($doc['champs'] ?? []);
            if (!array_key_exists($champ, $champs) || (string) $champs[$champ] !== (string) ($conditions['valeur'] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $cibles
     * @param array<string, mixed> $contexte
     */
    private function cibleContient(array $cibles, array $contexte): bool
    {
        $groupes = array_map('intval', (array) ($contexte['groupes'] ?? []));
        $roles = array_map('strval', (array) ($contexte['roles'] ?? []));
        $compte = (int) ($contexte['compte'] ?? 0);
        foreach ($cibles as $cible) {
            $type = (string) ($cible['type'] ?? '');
            $valeur = (string) ($cible['valeur'] ?? '');
            if ($type === 'groupe' && in_array((int) $valeur, $groupes, true)) {
                return true;
            }
            if ($type === 'role' && in_array($valeur, $roles, true)) {
                return true;
            }
            if ($type === 'compte' && $compte > 0 && (int) $valeur === $compte) {
                return true;
            }
        }

        return false;
    }

    /**
     * Traduit les conditions d'une règle en prédicat SQL sur un alias de la table `documents`
     * (colonnes `type`, `meta`, `id`). Une clé de champ impossible ou une table d'états absente rend
     * `1 = 0` : la règle ne retient alors aucun document (aucune restriction n'est inventée).
     *
     * @param array<string, mixed> $conditions
     * @return array{0:string, 1:list<mixed>}
     */
    private function conditionsSql(string $alias, array $conditions): array
    {
        $parts = [];
        $params = [];
        $classe = $conditions['classe'] ?? null;
        if (is_string($classe) && $classe !== '') {
            $parts[] = "BINARY $alias.type = ?";
            $params[] = $classe;
        }
        $champ = $conditions['champ'] ?? null;
        if (is_string($champ) && $champ !== '') {
            if (preg_match('/^[A-Za-z0-9_-]+$/', $champ) !== 1) {
                return ['1 = 0', []];
            }
            $parts[] = "JSON_UNQUOTE(JSON_EXTRACT($alias.meta, '\$.champs." . $champ . "')) = ?";
            $params[] = (string) ($conditions['valeur'] ?? '');
        }
        $etat = $conditions['etat'] ?? null;
        if (is_string($etat) && $etat !== '') {
            if (!$this->tableEtatDisponible()) {
                return ['1 = 0', []];
            }
            $parts[] = "EXISTS (SELECT 1 FROM workflow_instances wf WHERE wf.document_id = $alias.id AND wf.etat = ?)";
            $params[] = $etat;
        }
        if ($parts === []) {
            return ['1 = 1', []];
        }

        return ['(' . implode(' AND ', $parts) . ')', $params];
    }

    private function tableEtatDisponible(): bool
    {
        if ($this->etatDisponible !== null) {
            return $this->etatDisponible;
        }
        if ($this->pdo === null) {
            return $this->etatDisponible = false;
        }
        try {
            $this->etatDisponible = $this->pdo->query("SHOW TABLES LIKE 'workflow_instances'")->fetchColumn() !== false;
        } catch (\PDOException) {
            $this->etatDisponible = false;
        }

        return $this->etatDisponible;
    }

    /** État effectif d'un document (premier circuit actif), ou null s'il n'est dans aucun circuit. */
    public function etat(int $document): ?string
    {
        if ($this->pdo === null || !$this->tableEtatDisponible()) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT etat FROM workflow_instances WHERE document_id = ? ORDER BY id LIMIT 1');
            $stmt->execute([$document]);
            $etat = $stmt->fetchColumn();
        } catch (\PDOException) {
            return null;
        }

        return $etat === false ? null : (string) $etat;
    }

    /**
     * Catalogue des circuits à états (M-Files « workflows ») : les règles nommées s'y réfèrent par leur
     * état. Lecture seule du moteur `Ged\Workflows`, aucune seconde machine à états.
     *
     * @return list<array<string, mixed>>
     */
    public function circuits(): array
    {
        return (new Circuits($this->pdoObligatoire('circuits')))->toutes();
    }

    /**
     * États nommés d'un circuit (M-Files `/structure/workflows/(id)/states`).
     *
     * @return list<array<string, mixed>>
     */
    public function etats(int $circuit): array
    {
        $definition = (new Circuits($this->pdoObligatoire('etats')))->parId($circuit);
        if ($definition === null) {
            throw new RuntimeException("circuit de workflow inconnu : $circuit");
        }

        return array_values(array_filter((array) ($definition['etats'] ?? []), 'is_array'));
    }

    /**
     * Transitions nommées d'un circuit, chacune avec sa permission (M-Files
     * `/structure/workflows/(id)/statetransitions`).
     *
     * @return list<array<string, mixed>>
     */
    public function transitions(int $circuit): array
    {
        $definition = (new Circuits($this->pdoObligatoire('transitions')))->parId($circuit);
        if ($definition === null) {
            throw new RuntimeException("circuit de workflow inconnu : $circuit");
        }

        return array_values(array_filter((array) ($definition['transitions'] ?? []), 'is_array'));
    }

    /**
     * Change l'état d'un document (M-Files PUT workflowstate) : la transition est soumise à son droit et
     * tracée par le moteur `Ged\Workflows`. Un refus est nommé (jamais un 500 muet).
     *
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function transitionner(int $document, string $vers, string $auteur, array $permissions): array
    {
        return (new MoteurWorkflows($this->pdoObligatoire('transition')))
            ->transitionner($document, $vers, $auteur, $permissions);
    }

    private function pdoObligatoire(string $etape): PDO
    {
        return $this->pdo ?? throw new RuntimeException(
            "permissions dynamiques : base absente (étape « $etape »), exécutez tools/installer.php",
        );
    }
}
