<?php

declare(strict_types=1);

namespace Ged\Recherche;

use PDO;

/**
 * Moteur de recherche plein texte : index FULLTEXT d'abord, repli mot à mot (LIKE) quand il ne peut pas
 * répondre — index absent, requête sans terme exploitable, ou fragment interne (FULLTEXT ne cherche que
 * des débuts de mot). Les filtres type/émetteur/date/montant s'appliquent DANS LA MÊME requête que le
 * texte : liste et total partagent donc exactement le même périmètre.
 *
 * L'index est bâti HORS requête par les jobs (`tools/indexer-recherche.php`, `tools/tache.php indexation`) :
 * une recherche ne relit jamais le fonds. `IndexPleinTexte` reste injecté pour ne pas changer le branchement.
 *
 * Le classement final se fait en PHP sur un nombre borné de candidats, pour départager titre et corps de
 * façon lisible (pertinence = 3 × occurrence dans le titre + occurrences du corps, plafonnées).
 */
final class Service implements Moteur
{
    /** Candidats ramenés de la base avant reclassement ; borne mémoire et temps. */
    private const CANDIDATS_MAX = 500;
    /** Le comptage de pertinence ne lit que le début d'un texte très long. */
    private const PERTINENCE_MAX = 20000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly IndexPleinTexte $index,
        private readonly ?int $compte = null,
        private readonly bool $administrateur = false,
    ) {
    }

    public function rechercher(Requete $requete): Resultat
    {
        $debut = microtime(true);
        try {
            $termes = Termes::depuis($requete->texte);
            $pleinTexte = Schema::pleinTexteUtilisable($this->pdo) && $termes->expressionFulltext() !== '';
            $mode = $pleinTexte ? 'fulltext' : ($termes->positifs() === [] ? 'liste' : 'like');

            $lignes = match ($mode) {
                'fulltext' => $this->chercherPleinTexte($requete, $termes),
                'like' => $this->chercherLike($requete, $termes),
                default => $this->lister($requete),
            };
            if ($mode === 'fulltext' && $lignes === []) {
                // Fragment interne ou terme non indexé : FULLTEXT n'a rien su rendre, LIKE essaie.
                $lignes = $this->chercherLike($requete, $termes);
                $mode = 'like';
            }

            return new Resultat(
                $this->classer($lignes, $termes, $mode, $requete->limite),
                $this->compter($requete, $termes, $mode),
                $mode,
                $this->millisecondes($debut),
            );
        } catch (\PDOException $erreur) {
            return Resultat::enErreur(ErreurSql::nommer($erreur, 'recherche plein texte'), $this->millisecondes($debut));
        }
    }

    /** Recherche FULLTEXT booléenne, classée par pertinence de la base puis reclassée. */
    private function chercherPleinTexte(Requete $requete, Termes $termes): array
    {
        $conditions = $this->conditions($requete, $termes, 'fulltext');
        $parametres = $conditions['parametres'];
        $parametres['score_expression'] = $termes->expressionFulltext();
        $sql = 'SELECT t.chemin, t.document_id, t.titre, t.type, t.emetteur, t.date_document, t.montant, t.contenu,'
            . ' MATCH(t.titre, t.contenu) AGAINST(:score_expression IN BOOLEAN MODE) AS score'
            . ' FROM recherche_textes t WHERE ' . $conditions['sql']
            . ' ORDER BY score DESC, t.date_document DESC, t.document_id DESC'
            . ' LIMIT ' . self::CANDIDATS_MAX;

        return $this->executer($sql, $parametres);
    }

    /** Repli mot à mot : chaque terme positif doit apparaître (titre ou corps), les exclus sont écartés. */
    private function chercherLike(Requete $requete, Termes $termes): array
    {
        $conditions = $this->conditions($requete, $termes, 'like');
        $sql = 'SELECT t.chemin, t.document_id, t.titre, t.type, t.emetteur, t.date_document, t.montant, t.contenu'
            . ' FROM recherche_textes t WHERE ' . $conditions['sql']
            . ' ORDER BY t.date_document DESC, t.document_id DESC LIMIT ' . self::CANDIDATS_MAX;

        return $this->executer($sql, $conditions['parametres']);
    }

    /** Sans terme (filtres seuls, écran vierge) : le fonds filtré, du plus récent au plus ancien. */
    private function lister(Requete $requete): array
    {
        $conditions = $this->conditions($requete, Termes::depuis(''), 'liste');
        $sql = 'SELECT t.chemin, t.document_id, t.titre, t.type, t.emetteur, t.date_document, t.montant, t.contenu'
            . ' FROM recherche_textes t WHERE ' . $conditions['sql']
            . ' ORDER BY t.date_document DESC, t.document_id DESC LIMIT ' . self::CANDIDATS_MAX;

        return $this->executer($sql, $conditions['parametres']);
    }

    /** Total du MÊME périmètre que la liste : mêmes conditions, sans limite. */
    private function compter(Requete $requete, Termes $termes, string $mode): int
    {
        $conditions = $this->conditions($requete, $termes, $mode === 'erreur' ? 'liste' : $mode);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM recherche_textes t WHERE ' . $conditions['sql']);
        $stmt->execute($conditions['parametres']);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Conditions communes au texte et aux filtres. Le mode `fulltext` pose le MATCH, `like` pose les
     * LIKE, `liste` ne pose aucun terme : les filtres, eux, sont toujours là et au même endroit.
     *
     * @return array{sql:string, parametres:array<string, mixed>}
     */
    private function conditions(Requete $requete, Termes $termes, string $mode): array
    {
        // Une ligne dont le fichier a disparu du fonds reste en base mais sort du périmètre cherché.
        $morceaux = ['t.absent_le IS NULL'];
        $parametres = [];

        // Le périmètre ACL appartient à la requête elle-même : l'index ne livre jamais au PHP un
        // document que le compte ne peut lire. Une règle de niveau supérieur inclut la lecture ; le
        // refus explicite « lire » du dossier le plus proche gagne, comme DroitsDossiers.
        if ($this->compte !== null && !$this->administrateur) {
            $morceaux[] = "t.document_id IS NOT NULL AND EXISTS (\n"
                . " SELECT 1 FROM securite_dossiers_droits droit\n"
                . " INNER JOIN securite_groupes_comptes membre ON membre.groupe_id = droit.groupe_id\n"
                . " INNER JOIN securite_dossiers dossier ON dossier.id = droit.dossier_id\n"
                . " INNER JOIN documents document_acl ON document_acl.id = t.document_id\n"
                . " WHERE membre.compte_id = :acl_compte\n"
                . " AND (document_acl.chemin_relatif = dossier.chemin_relatif OR document_acl.chemin_relatif LIKE CONCAT(dossier.chemin_relatif, '/%'))\n"
                . " AND droit.action_code IN ('lire', 'deposer', 'modifier', 'gerer') AND droit.autorise = 1\n"
                . " AND NOT EXISTS (SELECT 1 FROM securite_dossiers_droits refus\n"
                . "   INNER JOIN securite_groupes_comptes membre_refus ON membre_refus.groupe_id = refus.groupe_id\n"
                . "   WHERE refus.dossier_id = droit.dossier_id AND membre_refus.compte_id = :acl_compte_refus\n"
                . "   AND refus.action_code = 'lire' AND refus.autorise = 0)\n"
                . " AND NOT EXISTS (SELECT 1 FROM securite_dossiers_droits plus_proche\n"
                . "   INNER JOIN securite_groupes_comptes membre_proche ON membre_proche.groupe_id = plus_proche.groupe_id\n"
                . "   INNER JOIN securite_dossiers dossier_proche ON dossier_proche.id = plus_proche.dossier_id\n"
                . "   WHERE membre_proche.compte_id = :acl_compte_proche\n"
                . "   AND (document_acl.chemin_relatif = dossier_proche.chemin_relatif OR document_acl.chemin_relatif LIKE CONCAT(dossier_proche.chemin_relatif, '/%'))\n"
                . "   AND CHAR_LENGTH(dossier_proche.chemin_relatif) > CHAR_LENGTH(dossier.chemin_relatif))\n"
                . ")";
            $parametres['acl_compte'] = $this->compte;
            $parametres['acl_compte_refus'] = $this->compte;
            $parametres['acl_compte_proche'] = $this->compte;
        }

        if ($mode === 'fulltext' && $termes->expressionFulltext() !== '') {
            $morceaux[] = 'MATCH(t.titre, t.contenu) AGAINST(:plein_texte IN BOOLEAN MODE)';
            $parametres['plein_texte'] = $termes->expressionFulltext();
        } elseif ($mode === 'like') {
            foreach ($termes->motifsLike() as $i => $motif) {
                $morceaux[] = "(t.titre LIKE :like_titre_$i OR t.contenu LIKE :like_corps_$i)";
                $parametres["like_titre_$i"] = $motif;
                $parametres["like_corps_$i"] = $motif;
            }
            foreach ($termes->motifsExclus() as $i => $motif) {
                $morceaux[] = "(t.titre NOT LIKE :exclu_titre_$i AND t.contenu NOT LIKE :exclu_corps_$i)";
                $parametres["exclu_titre_$i"] = $motif;
                $parametres["exclu_corps_$i"] = $motif;
            }
        }

        if ($requete->type !== null) {
            $morceaux[] = 't.type = :filtre_type';
            $parametres['filtre_type'] = $requete->type;
        }
        if ($requete->emetteur !== null) {
            $morceaux[] = 't.emetteur LIKE :filtre_emetteur';
            $parametres['filtre_emetteur'] = '%' . self::echapperLike($requete->emetteur) . '%';
        }
        if ($requete->dateDebut !== null) {
            $morceaux[] = 't.date_document >= :filtre_date_debut';
            $parametres['filtre_date_debut'] = $requete->dateDebut;
        }
        if ($requete->dateFin !== null) {
            $morceaux[] = 't.date_document <= :filtre_date_fin';
            $parametres['filtre_date_fin'] = $requete->dateFin;
        }
        if ($requete->montantMin !== null) {
            $morceaux[] = 't.montant >= :filtre_montant_min';
            $parametres['filtre_montant_min'] = $requete->montantMin;
        }
        if ($requete->montantMax !== null) {
            $morceaux[] = 't.montant <= :filtre_montant_max';
            $parametres['filtre_montant_max'] = $requete->montantMax;
        }

        return ['sql' => $morceaux === [] ? '1 = 1' : implode(' AND ', $morceaux), 'parametres' => $parametres];
    }

    /**
     * @param list<array<string, mixed>> $lignes
     * @return list<Trouvaille>
     */
    private function classer(array $lignes, Termes $termes, string $mode, int $limite): array
    {
        $termesRecherches = $mode === 'liste' ? [] : $termes->positifs();
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
                $this->pertinence($titre, $contenu, $termesRecherches),
                Extraits::segments($titre, $termesRecherches),
                Extraits::extraire($contenu, $termesRecherches),
            );
        }

        usort($trouvailles, static fn (Trouvaille $a, Trouvaille $b): int => [$b->pertinence, $b->date ?? '', $b->id]
            <=> [$a->pertinence, $a->date ?? '', $a->id]);

        return array_slice($trouvailles, 0, $limite);
    }

    /** @param list<string> $termes */
    private function pertinence(string $titre, string $contenu, array $termes): float
    {
        if ($termes === []) {
            return 0.0;
        }
        $titreBas = mb_strtolower($titre);
        $corpsBas = mb_strtolower(mb_substr($contenu, 0, self::PERTINENCE_MAX));
        $score = 0.0;
        foreach ($termes as $terme) {
            $terme = mb_strtolower($terme);
            if ($terme === '') {
                continue;
            }
            $score += 3.0 * min(substr_count($titreBas, $terme), 3);
            $score += (float) min(substr_count($corpsBas, $terme), 5);
        }

        return $score;
    }

    /** @param array<string, mixed> $parametres @return list<array<string, mixed>> */
    private function executer(string $sql, array $parametres): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parametres);

        return $stmt->fetchAll();
    }

    private function millisecondes(float $debut): float
    {
        return (microtime(true) - $debut) * 1000.0;
    }

    private static function echapperLike(string $valeur): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $valeur);
    }
}
