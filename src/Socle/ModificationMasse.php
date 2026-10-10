<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\JournalAudit;
use RuntimeException;
use Throwable;

/**
 * Modification en masse (lot ged-parite-masse) : un lot de documents reçoit la MÊME opération, mais
 * chacun passe par le service d'édition UNITAIRE (`DepotFichiers::mettreAJour` / `miseALaCorbeille`).
 * Il en sort donc les mêmes effets : `.meta.json` réécrit à côté du fichier, versions et déplacements
 * tracés, aucun octet supprimé.
 *
 * Deux garanties non négociables :
 * - DROITS : un document dont le compte n'a pas le niveau `modifier` sur son dossier est REFUSÉ, jamais
 *   modifié ; le bilan nomme la raison de chaque refus (compte synthétique des oracles : pas d'ACL) ;
 * - TRAITEMENT LONG : le lot s'exécute par paquets COURTS et REPRENABLES. Le curseur vit dans le fonds
 *   (`.ged/modification-masse.json`, via `Registre`), jamais seulement en mémoire : une coupure au
 *   milieu du lot repart du dernier point publié. Aucun appel PHP de plusieurs minutes.
 *
 * Reprise : l'opération est idempotente (poser un type, poser un émetteur, ajouter une étiquette sans
 * doublon, déplacer vers un dossier, mettre à la corbeille) : rejouer une tranche interrompue ne
 * duplique aucun effet.
 */
final class ModificationMasse
{
    /** Taille d'un paquet : bornée pour garder chaque appel court. */
    public const LOT_DEFAUT = 50;
    public const LOT_MAX = 100;

    /** Actions admises, alignées sur l'édition en masse de Paperless-ngx (adaptées au modèle GEDv2). */
    public const ACTIONS = ['type', 'emetteur', 'etiquettes_ajouter', 'etiquettes_retirer', 'dossier', 'corbeille'];

    private const REGISTRE = 'modification-masse';

    private readonly DepotFichiers $depot;
    private readonly Registre $registre;
    private readonly DroitsDossiers $droits;
    private readonly JournalAudit $audit;

    /**
     * @param int|null $compte compte réel du lecteur (null = compte synthétique des oracles : pas d'ACL)
     * @param bool     $administrateur permission '*' : voit et modifie tout
     */
    public function __construct(Config $config, private readonly ?int $compte = null, private readonly bool $administrateur = false)
    {
        $this->depot = new DepotFichiers($config);
        $this->registre = new Registre($config);
        $this->droits = new DroitsDossiers(Base::pdo($config));
        $this->audit = new JournalAudit($config->chemin('SECURITE_DOSSIER', 'var/securite') . '/audit.ndjson');
    }

    /**
     * Met en file une modification en masse. Refuse un second lancement tant qu'une tâche est en cours.
     *
     * @param list<int>             $ids       documents visés
     * @param array<string, mixed>  $operation ['action' => …, 'valeur' => …]
     * @return array<string, mixed> état de la tâche créée
     */
    public function lancer(array $ids, array $operation, string $auteur): array
    {
        $ids = $this->normaliserIds($ids);
        $operation = $this->normaliserOperation($operation);
        if ($ids === []) {
            throw new RuntimeException('modification en masse : aucun document sélectionné');
        }

        return $this->registre->modifier(self::REGISTRE, function (array $etat) use ($ids, $operation, $auteur): array {
            if (($etat['etat'] ?? null) === 'en_cours') {
                throw new RuntimeException('modification en masse : une tâche est déjà en cours');
            }

            return [
                'id' => (int) ($etat['id'] ?? 0) + 1,
                'etat' => 'en_cours',
                'action' => (string) $operation['action'],
                'valeur' => $operation['valeur'],
                'ids' => $ids,
                'curseur' => 0,
                'traites' => 0,
                'refuses' => [],
                'auteur' => $auteur,
                'compte' => $this->compte,
                'administrateur' => $this->administrateur,
                'erreur' => null,
            ];
        });
    }

    /**
     * Traite le paquet suivant (borné) et publie l'état sous le verrou du registre. Une exception
     * imprévue marque la tâche `echec` avec sa cause : jamais un demi-lot silencieux.
     *
     * @return array<string, mixed>
     */
    public function avancer(int $id, int $lot = self::LOT_DEFAUT): array
    {
        $lot = max(1, min(self::LOT_MAX, $lot));
        try {
            $brut = $this->registre->modifier(self::REGISTRE, function (array $etat) use ($id, $lot): array {
                if ((int) ($etat['id'] ?? 0) !== $id) {
                    throw new RuntimeException('modification en masse : tâche introuvable');
                }
                if (($etat['etat'] ?? null) !== 'en_cours') {
                    return $etat;   // déjà terminée : rien à faire, aucun doublon
                }
                $ids = array_values(array_map('intval', (array) ($etat['ids'] ?? [])));
                $operation = ['action' => (string) ($etat['action'] ?? ''), 'valeur' => $etat['valeur'] ?? null];
                $auteur = (string) ($etat['auteur'] ?? 'inconnu');
                $compte = is_int($etat['compte'] ?? null) ? $etat['compte'] : null;
                $administrateur = (bool) ($etat['administrateur'] ?? false);

                $curseur = max(0, (int) ($etat['curseur'] ?? 0));
                $fin = min(count($ids), $curseur + $lot);
                for (; $curseur < $fin; $curseur++) {
                    $resultat = $this->appliquerUn($ids[$curseur], $operation, $auteur, $compte, $administrateur);
                    if ($resultat['ok']) {
                        $etat['traites'] = (int) $etat['traites'] + 1;
                    } else {
                        $refuses = (array) ($etat['refuses'] ?? []);
                        $refuses[] = $resultat;
                        $etat['refuses'] = $refuses;
                    }
                }
                $etat['curseur'] = $curseur;
                $etat['etat'] = $curseur >= count($ids) ? 'termine' : 'en_cours';

                return $etat;
            });

            return $this->etat($id) ?? $brut;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->registre->modifier(self::REGISTRE, static function (array $etat) use ($id, $e): array {
                if ((int) ($etat['id'] ?? 0) === $id) {
                    $etat['etat'] = 'echec';
                    $etat['erreur'] = mb_substr($e->getMessage(), 0, 512);
                }

                return $etat;
            });
            throw new RuntimeException('modification en masse : tâche en échec — ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * État publié d'une tâche (le dernier lancé si $id est null), ou null.
     *
     * @return array<string, mixed>|null
     */
    public function etat(?int $id = null): ?array
    {
        $etat = $this->registre->lire(self::REGISTRE);
        if (!isset($etat['id']) || ($id !== null && (int) $etat['id'] !== $id)) {
            return null;
        }
        $ids = (array) ($etat['ids'] ?? []);
        $refuses = array_values(array_map(static fn (array $r): array => [
            'id' => (int) ($r['id'] ?? 0),
            'raison' => (string) ($r['raison'] ?? 'refusé'),
        ], array_filter((array) ($etat['refuses'] ?? []), 'is_array')));

        return [
            'id' => (int) $etat['id'],
            'etat' => (string) ($etat['etat'] ?? 'inconnu'),
            'action' => (string) ($etat['action'] ?? ''),
            'valeur' => $etat['valeur'] ?? null,
            'total' => count($ids),
            'traite' => (int) ($etat['curseur'] ?? 0),
            'traites' => (int) ($etat['traites'] ?? 0),
            'refuses' => $refuses,
            'auteur' => (string) ($etat['auteur'] ?? ''),
            'erreur' => is_scalar($etat['erreur'] ?? null) ? (string) $etat['erreur'] : null,
        ];
    }

    /**
     * Applique l'opération à UN document. Un refus est rendu (jamais une exception) pour que le bilan
     * dise combien ont été traités, combien refusés, et pourquoi.
     *
     * @param array<string, mixed> $operation
     * @return array{id:int, ok:bool, raison:?string}
     */
    private function appliquerUn(int $idDocument, array $operation, string $auteur, ?int $compte, bool $administrateur): array
    {
        $fiche = $this->depot->fiche($idDocument);
        if ($fiche === null) {
            return ['id' => $idDocument, 'ok' => false, 'raison' => 'document introuvable'];
        }
        if ($fiche['supprime_le'] !== null) {
            return ['id' => $idDocument, 'ok' => false, 'raison' => 'document déjà à la corbeille'];
        }
        if (!$this->aLeDroitDEcriture($idDocument, $compte, $administrateur)) {
            return ['id' => $idDocument, 'ok' => false, 'raison' => 'droits insuffisants : modification refusée sur ce dossier'];
        }

        try {
            if (($operation['action'] ?? '') === 'corbeille') {
                $this->depot->miseALaCorbeille($idDocument, $auteur);
            } else {
                $this->depot->mettreAJour($idDocument, $this->metaPour($fiche, $operation), $auteur);
            }
        } catch (Throwable $e) {
            return ['id' => $idDocument, 'ok' => false, 'raison' => mb_substr($e->getMessage(), 0, 512)];
        }

        $this->tracer($auteur, $idDocument, (string) $operation['action']);

        return ['id' => $idDocument, 'ok' => true, 'raison' => null];
    }

    /** Un document n'est modifiable que si le compte a le niveau `modifier` sur son dossier de droits. */
    private function aLeDroitDEcriture(int $idDocument, ?int $compte, bool $administrateur): bool
    {
        if ($administrateur || $compte === null || $compte < 1) {
            return true;   // permission '*' ou compte synthétique des oracles : pas d'ACL à opposer
        }
        $dossier = $this->droits->dossierPourDocument($idDocument);

        return $dossier !== null && $this->droits->autorise($compte, $dossier, 'modifier');
    }

    /**
     * Métadonnées transmises au service unitaire pour l'action demandée. Les étiquettes partent de la
     * valeur COURANTE du document (le dossier fait foi) : ajouter ne duplique pas, retirer n'efface que
     * les libellés visés.
     *
     * @param array<string, mixed> $fiche
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function metaPour(array $fiche, array $operation): array
    {
        $valeur = $operation['valeur'] ?? null;
        $actuelles = array_values(array_map('strval', (array) ($fiche['etiquettes'] ?? [])));

        return match ((string) $operation['action']) {
            'type' => ['type' => (string) $valeur],
            'emetteur' => ['emetteur' => (string) $valeur],
            'dossier' => ['dossier' => (string) $valeur],
            'etiquettes_ajouter' => ['etiquettes' => array_values(array_unique([...$actuelles, ...$this->listerValeurs($valeur)]))],
            'etiquettes_retirer' => ['etiquettes' => array_values(array_diff($actuelles, $this->listerValeurs($valeur)))],
            default => throw new RuntimeException('modification en masse : action inconnue'),
        };
    }

    /**
     * @param list<int|string> $ids
     * @return list<int>
     */
    private function normaliserIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param array<string, mixed> $brut
     * @return array{action:string, valeur:mixed}
     */
    private function normaliserOperation(array $brut): array
    {
        $action = (string) ($brut['action'] ?? '');
        if (!in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException('modification en masse : action inconnue (type, emetteur, etiquettes_ajouter, '
                . 'etiquettes_retirer, dossier, corbeille)');
        }
        $valeur = $brut['valeur'] ?? null;

        if (in_array($action, ['etiquettes_ajouter', 'etiquettes_retirer'], true)) {
            $valeurs = $this->listerValeurs($valeur);
            if ($valeurs === []) {
                throw new RuntimeException('modification en masse : au moins une étiquette est requise');
            }

            return ['action' => $action, 'valeur' => $valeurs];
        }
        if ($action === 'corbeille') {
            return ['action' => $action, 'valeur' => null];   // geste sans paramètre : mise en corbeille
        }
        if (!is_string($valeur) || trim($valeur) === '') {
            throw new RuntimeException('modification en masse : une valeur est requise pour « ' . $action . ' »');
        }
        $valeur = trim($valeur);
        if ($action === 'dossier' && (str_contains($valeur, '..') || str_starts_with($valeur, '/'))) {
            throw new RuntimeException('modification en masse : dossier cible invalide');
        }

        return ['action' => $action, 'valeur' => $valeur];
    }

    /**
     * Une valeur d'étiquettes accepte une liste ou du texte séparé par des virgules.
     *
     * @return list<string>
     */
    private function listerValeurs(mixed $valeur): array
    {
        $bruts = is_array($valeur) ? $valeur : explode(',', (string) $valeur);
        $propres = [];
        foreach ($bruts as $brut) {
            $libelle = trim((string) $brut);
            if ($libelle !== '' && !in_array($libelle, $propres, true)) {
                $propres[] = $libelle;
            }
        }

        return $propres;
    }

    /** Journal d'audit en ajout seul : un refus d'y écrire ne remet jamais en cause la modification faite. */
    private function tracer(string $auteur, int $idDocument, string $action): void
    {
        try {
            $this->audit->ajouter('modification_masse', $auteur, ['document' => $idDocument, 'action' => $action]);
        } catch (Throwable $e) {
            error_log('audit modification_masse : ' . $e->getMessage());
        }
    }
}
