<?php

declare(strict_types=1);

namespace Ged\Recherche;

use Ged\Securite\DroitsDossiers;
use PDO;

/**
 * « Documents semblables » (parité Paperless `more like this`) : à partir d'un document de l'index, on
 * demande à l'index FULLTEXT les documents qui partagent ses termes significatifs, ou son émetteur.
 *
 * Le document lui-même est exclu par sa clé (stable), et le périmètre de lecture est posé DANS la requête
 * par `DroitsDossiers::predicatLecture` : l'index ne livre jamais à PHP un document que le compte ne peut
 * pas lire. Rien n'est filtré après coup.
 *
 * Choix du mode : `MATCH … AGAINST` en BOOLEAN MODE, pas en mode naturel. Le mode naturel de MyISAM écarte
 * par défaut les mots présents dans 50 % des lignes — inutilisable sur un fonds de factures qui partagent
 * « facture » — et `WITH QUERY EXPANSION` reconstruit son expansion depuis un top de lignes, ce qui laisse
 * dériver une liste pourtant déterministe. Le mode booléen, sur le même index, n'a pas ce seuil.
 */
final class Semblables
{
    public const MODE = 'semblable';
    public const LIMITE_DEFAUT = 12;
    public const LIMITE_MAX = 50;
    /** Un émetteur commun vaut mieux qu'un mot commun : il pèse en tête du classement. */
    private const BONUS_EMETTEUR = 4;
    /** Le type est un voisinage plus faible (toutes les factures se ressemblent un peu). */
    private const BONUS_TYPE = 1;
    /** Candidats ramenés de la base avant filtrage fin : assez large pour écarter un mot fourre-tout. */
    private const CANDIDATS_MAX = 200;
    /** Nombre de termes significatifs qu'un candidat doit partager (sans émetteur commun). */
    private const PARTAGE_MINIMUM = 2;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?int $compte = null,
        private readonly bool $administrateur = false,
    ) {
    }

    /**
     * Liste bornée des documents semblables à `$documentId`, la source exclue.
     * Une source introuvable ou non lisible rend une liste vide, jamais une exception ; une panne SQL est
     * nommée (`Resultat::enErreur`).
     */
    public function pour(int $documentId, int $limite = self::LIMITE_DEFAUT): Resultat
    {
        $debut = microtime(true);
        $limite = max(1, min(self::LIMITE_MAX, $limite));
        try {
            $source = $this->lireSource($documentId);
            if ($source === null) {
                return new Resultat([], 0, self::MODE, self::millisecondes($debut));
            }
            $termes = TermesSemblables::depuis((string) $source['titre'], (string) $source['contenu']);
            $emetteur = self::chaine($source['emetteur'] ?? null);
            $lignes = $this->candidats($source, TermesSemblables::expression($termes), min(self::CANDIDATS_MAX, $limite * 10));
            $lignes = $this->retenir($lignes, $termes, $emetteur, $limite);

            return new Resultat(
                $this->enTrouvailles($lignes, $termes),
                count($lignes),
                self::MODE,
                self::millisecondes($debut),
            );
        } catch (\PDOException $erreur) {
            return Resultat::enErreur(ErreurSql::nommer($erreur, 'documents semblables'), self::millisecondes($debut));
        }
    }

    /**
     * Identité minimale de la source, pour l'en-tête de l'écran — `null` si le document est inconnu ou hors
     * des droits du compte. Une panne SQL remonte à l'appelant, qui la nomme : on ne confond pas « absent »
     * et « base injoignable ».
     *
     * @return array{document_id:int, titre:string}|null
     */
    public function source(int $documentId): ?array
    {
        $ligne = $this->lireSource($documentId);
        if ($ligne === null) {
            return null;
        }

        return ['document_id' => (int) ($ligne['document_id'] ?? 0), 'titre' => (string) $ligne['titre']];
    }

    /**
     * Ligne d'index de la source, bornée par les droits de lecture du compte quand il y en a un.
     *
     * @return array<string, mixed>|null
     */
    private function lireSource(int $documentId): ?array
    {
        $jointure = '';
        $conditions = ['t.document_id = ?', 't.absent_le IS NULL'];
        $parametres = [$documentId];
        [$jointure, $conditions, $parametres] = $this->appliquerDroits($jointure, $conditions, $parametres);

        $requete = $this->pdo->prepare('SELECT t.cle, t.document_id, t.titre, t.contenu, t.emetteur, t.type'
            . ' FROM recherche_textes t' . $jointure
            . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1');
        $requete->execute($parametres);
        $ligne = $requete->fetch();

        return is_array($ligne) ? $ligne : null;
    }

    /**
     * Candidats de la base, classés par ressemblance : score FULLTEXT du document, plus un bonus pour un
     * émetteur commun, plus un petit bonus de type. La source est exclue par clé.
     *
     * @param array<string, mixed> $source
     * @return list<array<string, mixed>>
     */
    private function candidats(array $source, string $expression, int $limite): array
    {
        $jointure = '';
        $conditions = ['t.absent_le IS NULL', 't.cle <> ?'];
        $parametres = [(string) $source['cle']];
        [$jointure, $conditions, $parametres] = $this->appliquerDroits($jointure, $conditions, $parametres);

        $emetteur = self::chaine($source['emetteur'] ?? null);
        $type = self::chaine($source['type'] ?? null);

        $candidats = [];
        if ($expression !== '') {
            $candidats[] = 'MATCH(t.titre, t.contenu) AGAINST(? IN BOOLEAN MODE)';
            $parametres[] = $expression;
        }
        if ($emetteur !== null) {
            $candidats[] = 't.emetteur = ?';
            $parametres[] = $emetteur;
        }
        if ($candidats === [] && $type !== null) {
            // Dernier recours : ni texte ni émetteur exploitables. On ne rend pas une liste vide muette.
            $candidats[] = 't.type = ?';
            $parametres[] = $type;
        }
        if ($candidats === []) {
            return [];
        }
        $conditions[] = '(' . implode(' OR ', $candidats) . ')';

        $scores = [];
        if ($expression !== '') {
            $scores[] = 'MATCH(t.titre, t.contenu) AGAINST(? IN BOOLEAN MODE)';
            $parametres[] = $expression;
        }
        if ($emetteur !== null) {
            $scores[] = '(CASE WHEN t.emetteur = ? THEN ' . self::BONUS_EMETTEUR . ' ELSE 0 END)';
            $parametres[] = $emetteur;
        }
        if ($type !== null) {
            $scores[] = '(CASE WHEN t.type = ? THEN ' . self::BONUS_TYPE . ' ELSE 0 END)';
            $parametres[] = $type;
        }
        if ($scores === []) {
            return [];
        }

        $sql = 'SELECT t.chemin, t.document_id, t.titre, t.type, t.emetteur, t.date_document, t.montant, t.contenu'
            . ' FROM recherche_textes t' . $jointure
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY (' . implode(' + ', $scores) . ') DESC, t.date_document DESC, t.document_id DESC'
            . ' LIMIT ' . $limite;

        return $this->executer($sql, $parametres);
    }

    /**
     * Ajoute le prédicat de droits DANS la requête (jointure `documents`), quand un compte non administrateur
     * est posé. Le fragment porte trois marqueurs positionnels liés au compte, dans l'ordre attendu.
     *
     * @param list<string> $conditions
     * @param list<mixed> $parametres
     * @return array{0:string, 1:list<string>, 2:list<mixed>}
     */
    private function appliquerDroits(string $jointure, array $conditions, array $parametres): array
    {
        if ($this->compte === null || $this->administrateur) {
            return [$jointure, $conditions, $parametres];
        }
        [$predicat, $acl] = DroitsDossiers::predicatLecture('d', $this->compte);

        return [$jointure . ' INNER JOIN documents d ON d.id = t.document_id', [...$conditions, $predicat], [...$parametres, ...$acl]];
    }

    /**
     * Filtrage fin : un émetteur commun suffit, sinon le candidat doit partager assez de termes significatifs
     * — un seul mot fourre-tout (« jour », « total ») ne fait pas un semblable. Un document sans terme ni
     * émetteur (repli type) garde ses candidats.
     *
     * @param list<array<string, mixed>> $lignes
     * @param list<string> $termes
     * @return list<array<string, mixed>>
     */
    private function retenir(array $lignes, array $termes, ?string $emetteur, int $limite): array
    {
        $minimum = $termes === [] ? 0 : (count($termes) >= 2 ? self::PARTAGE_MINIMUM : 1);
        $retenues = [];
        foreach ($lignes as $ligne) {
            $memeEmetteur = $emetteur !== null && self::chaine($ligne['emetteur'] ?? null) === $emetteur;
            if ($memeEmetteur || $this->partage($ligne, $termes) >= $minimum) {
                $retenues[] = $ligne;
            }
            if (count($retenues) >= $limite) {
                break;
            }
        }

        return $retenues;
    }

    /**
     * @param array<string, mixed> $ligne
     * @param list<string> $termes
     */
    private function partage(array $ligne, array $termes): int
    {
        $texte = mb_strtolower((string) ($ligne['titre'] ?? '') . "\n" . (string) ($ligne['contenu'] ?? ''));
        $partages = 0;
        foreach ($termes as $terme) {
            if (mb_stripos($texte, $terme) !== false) {
                $partages++;
            }
        }

        return $partages;
    }

    /**
     * @param list<array<string, mixed>> $lignes
     * @param list<string> $termes
     * @return list<Trouvaille>
     */
    private function enTrouvailles(array $lignes, array $termes): array
    {
        $trouvailles = [];
        foreach ($lignes as $ligne) {
            $titre = (string) $ligne['titre'];
            $contenu = (string) $ligne['contenu'];
            $trouvailles[] = new Trouvaille(
                (int) ($ligne['document_id'] ?? 0),
                (string) $ligne['chemin'],
                $titre,
                $ligne['type'] === null ? null : (string) $ligne['type'],
                $ligne['emetteur'] === null ? null : (string) $ligne['emetteur'],
                $ligne['date_document'] === null ? null : substr((string) $ligne['date_document'], 0, 10),
                $ligne['montant'] === null ? null : (float) $ligne['montant'],
                Pertinence::calculer($titre, $contenu, $termes),
                Extraits::segments($titre, $termes),
                Extraits::extraire($contenu, $termes),
            );
        }

        return $trouvailles;
    }

    /**
     * @param list<mixed> $parametres
     * @return list<array<string, mixed>>
     */
    private function executer(string $sql, array $parametres): array
    {
        $requete = $this->pdo->prepare($sql);
        $requete->execute($parametres);

        return $requete->fetchAll();
    }

    private static function chaine(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }

    private static function millisecondes(float $debut): float
    {
        return (microtime(true) - $debut) * 1000.0;
    }
}
