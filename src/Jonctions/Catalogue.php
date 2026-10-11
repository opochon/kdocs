<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Archivage\ErreurScellement;
use Ged\Base;
use Ged\Config;
use Ged\Contrats\Depot;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\RefusDepot;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Fichiers;
use Ged\Socle\Nomenclature;
use Ged\Socle\Vues;
use PDO;
use PDOException;

/**
 * Service de lecture et d'écriture de l'API REST des documents (lot ged-api-documents). Le compte du jeton
 * est passé EXPLICITEMENT : le périmètre des droits est posé DANS la requête SQL
 * (`DroitsDossiers::predicatLecture`) pour la liste, la recherche, les étiquettes et les émetteurs — jamais
 * un filtrage après coup. La fiche, le fichier et la version refusent un document hors dossiers.
 *
 * La recherche consulte l'index dérivé `recherche_textes` (FULLTEXT) et retombe sur un LIKE borné quand
 * l'index est absent ou qu'aucun terme indexable n'existe — dans les deux cas au même périmètre de droits.
 * Les écritures passent par le contrat `Depot` : le Socle applique la garde d'archivage et, pour tout
 * déplacement de rangement, le contrôle du niveau `deposer` sur le dossier d'ARRIVÉE.
 */
final class Catalogue
{
    public const TAILLE_DEFAUT = 25;
    public const TAILLE_MAX = 100;
    /** Bornes de la recherche : ni la mémoire ni le temps ne dépendent de la taille du fonds. */
    private const RECHERCHE_MAX = 1000;
    private const TERMES_MAX = 8;
    private const ETIQUETTES_SCAN_MAX = 5000;

    /** Clés de métadonnées que l'API accepte de modifier. */
    private const METADONNEES = ['titre', 'type', 'emetteur', 'date', 'montant', 'etiquettes', 'dossier'];

    private readonly PDO $pdo;
    private readonly Depot $depot;
    private readonly DroitsDossiers $droits;
    private readonly Nomenclature $nomenclature;
    private readonly Vues $vues;
    /** @var array<string, string> Niveau effectif par dossier, mémorisé le temps de la requête. */
    private array $niveaux = [];

    public function __construct(Config $config, private readonly int $compte, private readonly bool $administrateur = false)
    {
        $this->pdo = Base::pdo($config);
        $this->depot = new DepotFichiers($config);
        $this->droits = new DroitsDossiers($this->pdo);
        $this->nomenclature = new Nomenclature($config);
        $this->vues = new Vues($config);
    }

    /**
     * Page de documents filtrés, au périmètre des droits du compte. `q` bascule sur la recherche plein
     * texte (index puis repli LIKE), les filtres s'appliquent au même périmètre.
     *
     * @param array<string, mixed> $criteres
     * @return array{total:int, page:int, taille:int, pages:int, documents:list<array<string, mixed>>}
     * @throws ErreurApi (400 : filtre incohérent)
     */
    public function lister(array $criteres, string $etape = 'GET /api/v1/documents'): array
    {
        $taille = max(1, min(self::TAILLE_MAX, (int) ($criteres['taille'] ?? self::TAILLE_DEFAUT)));
        $page = max(1, (int) ($criteres['page'] ?? 1));
        $recherche = trim((string) ($criteres['q'] ?? ''));
        $filtres = $this->criteres($criteres, $etape);

        if ($recherche !== '') {
            $ids = $this->idsRecherche($recherche, $filtres);
            $total = count($ids);
            $pages = max(1, (int) ceil($total / $taille));
            $page = min($page, $pages);
            $documents = $this->parIdentifiants(array_slice($ids, ($page - 1) * $taille, $taille));
        } else {
            [$where, $params] = $this->wherePourFiltres($filtres);
            $total = $this->compter($where, $params);
            $pages = max(1, (int) ceil($total / $taille));
            $page = min($page, $pages);
            $documents = $this->page($where, $params, $taille, ($page - 1) * $taille);
        }

        return ['total' => $total, 'page' => $page, 'taille' => $taille, 'pages' => $pages, 'documents' => $documents];
    }

    /**
     * Fiche d'un document : refus nommé s'il est introuvable (404) ou hors des dossiers du compte (403).
     *
     * @return array<string, mixed>
     * @throws ErreurApi
     */
    public function fiche(int $id, string $etape): array
    {
        $ligne = $this->ligneVivante($id);
        if ($ligne === null) {
            throw new ErreurApi(404, "document introuvable : $id", $etape);
        }
        $this->exigerDroitLecture($id, $etape);
        $versions = $this->versions($id);
        $derniere = $versions === [] ? 0 : (int) $versions[array_key_last($versions)]['numero'];

        return $this->resume($ligne) + [
            'dossier' => self::dossierDe((string) $ligne['chemin_relatif']),
            'statut' => (string) $ligne['statut'],
            'empreinte' => (string) $ligne['empreinte'],
            'version' => $derniere,
            'versions' => $versions,
        ];
    }

    /**
     * Fichier courant (`$numero` null) ou version archivée : chemin absolu contrôlé, jamais hors racine.
     *
     * @return array{document:int, chemin:string, nom:string, mime:string, empreinte:string, version:int}
     * @throws ErreurApi
     */
    public function fichier(int $id, ?int $numero, string $etape): array
    {
        $ligne = $this->ligneVivante($id);
        if ($ligne === null) {
            throw new ErreurApi(404, "document introuvable : $id", $etape);
        }
        $this->exigerDroitLecture($id, $etape);
        try {
            $absolu = $this->depot->cheminAbsolu((string) $ligne['chemin_relatif']);
        } catch (\InvalidArgumentException $e) {
            throw new ErreurApi(404, "document $id : chemin invalide", $etape, $e);
        }

        if ($numero === null) {
            if (!is_file($absolu)) {
                throw new ErreurApi(404, "le fichier du document $id a disparu du fonds", $etape);
            }
            $versions = $this->versions($id);

            return ['document' => $id, 'chemin' => $absolu, 'nom' => (string) $ligne['nom'],
                'mime' => (string) $ligne['mime'], 'empreinte' => (string) $ligne['empreinte'],
                'version' => $versions === [] ? 0 : (int) $versions[array_key_last($versions)]['numero']];
        }

        $requete = $this->pdo->prepare('SELECT numero, nom_archive, empreinte, taille FROM document_versions WHERE document_id = ? AND numero = ?');
        $requete->execute([$id, $numero]);
        $version = $requete->fetch();
        if ($version === false) {
            throw new ErreurApi(404, "version $numero introuvable pour le document $id", $etape);
        }
        $archive = (string) $version['nom_archive'];
        if ($archive === '' || str_contains($archive, '/') || str_contains($archive, '\\') || str_contains($archive, '..')) {
            throw new ErreurApi(404, "version $numero : archive invalide", $etape);
        }
        $chemin = Fichiers::dossierVersions($absolu) . '/' . $archive;
        if (!is_file($chemin)) {
            throw new ErreurApi(404, "le fichier de la version $numero a disparu du fonds", $etape);
        }

        return ['document' => $id, 'chemin' => $chemin, 'nom' => basename($chemin),
            'mime' => (string) $ligne['mime'], 'empreinte' => (string) $version['empreinte'], 'version' => $numero];
    }

    /**
     * Modifie les métadonnées : droit `modifier` sur le document, puis le contrat `Depot` applique la garde
     * d'archivage et, si le rangement change, le droit `deposer` sur le dossier d'ARRIVÉE.
     *
     * @param array<string, mixed> $metadonnees
     * @return array<string, mixed> fiche après modification
     * @throws ErreurApi
     */
    public function modifier(int $id, array $metadonnees, string $auteur, string $etape): array
    {
        $ligne = $this->ligneVivante($id);
        if ($ligne === null) {
            throw new ErreurApi(404, "document introuvable : $id", $etape);
        }
        $this->exigerDroitModification($id, $etape);
        $propre = $this->validerMetadonnees($metadonnees, $etape);

        try {
            $this->depot->mettreAJour($id, $propre, $auteur, $this->compte, $this->administrateur);
        } catch (RefusDepot $e) {
            throw new ErreurApi(403, $e->getMessage(), $etape, $e);
        } catch (ErreurScellement $e) {
            throw new ErreurApi(409, $e->getMessage(), $etape, $e);
        } catch (\RuntimeException $e) {
            throw new ErreurApi(409, $e->getMessage(), $etape, $e);
        }

        return $this->fiche($id, $etape);
    }

    /** @return list<array{id:int, nom:string, actif:bool}> */
    public function types(): array
    {
        try {
            $types = $this->nomenclature->lister();
            if ($types !== []) {
                return $types;
            }
        } catch (\RuntimeException) {
            // Nomenclature illisible : on rend la liste livrée par défaut, jamais une liste vide muette.
        }
        $defauts = [];
        foreach (Nomenclature::TYPES_DEFAUT as $i => $nom) {
            $defauts[] = ['id' => $i + 1, 'nom' => $nom, 'actif' => true];
        }

        return $defauts;
    }

    /** @return list<string> Étiquettes des documents lisibles par ce compte. */
    public function etiquettes(): array
    {
        [$where, $params] = $this->wherePourFiltres($this->filtresVides());
        $requete = $this->pdo->prepare('SELECT d.etiquettes FROM documents d WHERE ' . $where
            . ' AND d.etiquettes IS NOT NULL LIMIT ' . self::ETIQUETTES_SCAN_MAX);
        $requete->execute($params);
        $etiquettes = [];
        foreach ($requete->fetchAll(PDO::FETCH_COLUMN) as $brut) {
            foreach ((array) json_decode((string) $brut, true) as $etiquette) {
                if (is_string($etiquette) && $etiquette !== '' && !in_array($etiquette, $etiquettes, true)) {
                    $etiquettes[] = $etiquette;
                }
            }
        }
        sort($etiquettes, SORT_NATURAL | SORT_FLAG_CASE);

        return $etiquettes;
    }

    /** @return list<string> Émetteurs des documents lisibles par ce compte. */
    public function emetteurs(): array
    {
        [$where, $params] = $this->wherePourFiltres($this->filtresVides());
        $requete = $this->pdo->prepare("SELECT DISTINCT d.emetteur FROM documents d WHERE $where"
            . " AND d.emetteur IS NOT NULL AND d.emetteur <> '' ORDER BY d.emetteur LIMIT 1000");
        $requete->execute($params);

        return array_values(array_map('strval', $requete->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Filtres neutres (aucune contrainte), à la forme attendue par `wherePourFiltres`.
     *
     * @return array{type:?string, emetteur:?string, etiquette:list<string>, date_debut:?string, date_fin:?string, dossier:?string}
     */
    private function filtresVides(): array
    {
        return ['type' => null, 'emetteur' => null, 'etiquette' => [], 'date_debut' => null, 'date_fin' => null, 'dossier' => null];
    }

    /** @return list<array{id:int, nom:string}> Vues enregistrées du fonds (configuration partagée). */
    public function vues(): array
    {
        try {
            $vues = [];
            foreach ($this->vues->lister() as $vue) {
                $vues[] = ['id' => (int) $vue['id'], 'nom' => (string) $vue['nom']];
            }

            return $vues;
        } catch (\RuntimeException) {
            return [];
        }
    }

    /**
     * Filtres normalisés : une date non ISO est refusée 400 (jamais un filtre lâche silencieux).
     *
     * @param array<string, mixed> $criteres
     * @return array{type:?string, emetteur:?string, etiquette:list<string>, date_debut:?string, date_fin:?string, dossier:?string}
     * @throws ErreurApi
     */
    private function criteres(array $criteres, string $etape): array
    {
        $etiquettes = [];
        foreach ((array) ($criteres['etiquette'] ?? []) as $etiquette) {
            $etiquette = trim((string) $etiquette);
            if ($etiquette !== '' && !in_array($etiquette, $etiquettes, true)) {
                $etiquettes[] = $etiquette;
            }
        }
        $dossier = trim(str_replace('\\', '/', (string) ($criteres['dossier'] ?? '')), '/');
        if (str_contains($dossier, '..')) {
            throw new ErreurApi(400, "filtre dossier invalide : « $dossier »", $etape);
        }

        return [
            'type' => $this->chaine($criteres['type'] ?? null),
            'emetteur' => $this->chaine($criteres['emetteur'] ?? null),
            'etiquette' => $etiquettes,
            'date_debut' => $this->date($criteres['date_debut'] ?? null, 'date_debut', $etape),
            'date_fin' => $this->date($criteres['date_fin'] ?? null, 'date_fin', $etape),
            'dossier' => $dossier === '' ? null : $dossier,
        ];
    }

    private function chaine(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }

    /** @throws ErreurApi */
    private function date(mixed $valeur, string $nom, string $etape): ?string
    {
        $valeur = trim((string) $valeur);
        if ($valeur === '') {
            return null;
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $valeur, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ErreurApi(400, "filtre $nom : « $valeur » n’est pas une date AAAA-MM-JJ valide", $etape);
        }

        return $valeur;
    }

    /**
     * Clause WHERE commune à la liste, à la recherche et aux référentiels.
     *
     * @param array{type:?string, emetteur:?string, etiquette:list<string>, date_debut:?string, date_fin:?string, dossier:?string} $filtres
     * @return array{0:string, 1:list<mixed>}
     */
    private function wherePourFiltres(array $filtres): array
    {
        $clauses = ['d.supprime_le IS NULL', 'd.absent_depuis IS NULL'];
        $params = [];
        if (($filtres['type'] ?? null) !== null) {
            $clauses[] = 'BINARY d.type = ?';
            $params[] = $filtres['type'];
        }
        if (($filtres['emetteur'] ?? null) !== null) {
            $clauses[] = 'BINARY d.emetteur = ?';
            $params[] = $filtres['emetteur'];
        }
        if (($filtres['date_debut'] ?? null) !== null) {
            $clauses[] = 'd.date_document >= ?';
            $params[] = $filtres['date_debut'];
        }
        if (($filtres['date_fin'] ?? null) !== null) {
            $clauses[] = 'd.date_document <= ?';
            $params[] = $filtres['date_fin'];
        }
        foreach ($filtres['etiquette'] as $etiquette) {
            $clauses[] = 'JSON_CONTAINS(d.etiquettes, ?)';
            $params[] = (string) json_encode($etiquette, JSON_UNESCAPED_UNICODE);
        }
        if (($filtres['dossier'] ?? null) !== null) {
            $clauses[] = '(d.chemin_relatif = ? OR d.chemin_relatif LIKE ?)';
            $params[] = $filtres['dossier'];
            $params[] = $filtres['dossier'] . '/%';
        }
        if (!$this->administrateur) {
            [$acl, $aclParams] = DroitsDossiers::predicatLecture('d', $this->compte);
            $clauses[] = $acl;
            $params = [...$params, ...$aclParams];
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Identifiants de la recherche, dans l'ordre d'affichage, bornés et AU MÊME périmètre de droits.
     *
     * @param array{type:?string, emetteur:?string, etiquette:list<string>, date_debut:?string, date_fin:?string, dossier:?string} $filtres
     * @return list<int>
     */
    private function idsRecherche(string $recherche, array $filtres): array
    {
        [$where, $params] = $this->wherePourFiltres($filtres);
        $termes = self::termes($recherche);

        if ($termes !== []) {
            try {
                $expression = implode(' ', array_map(static fn (string $t): string => '+' . $t . '*', $termes));
                $requete = $this->pdo->prepare('SELECT d.id FROM documents d INNER JOIN recherche_textes t ON t.document_id = d.id'
                    . ' WHERE ' . $where . ' AND t.absent_le IS NULL AND MATCH(t.titre, t.contenu) AGAINST(? IN BOOLEAN MODE)'
                    . ' ORDER BY d.date_document DESC, d.id DESC LIMIT ' . self::RECHERCHE_MAX);
                $requete->execute([...$params, $expression]);
                $ids = array_map('intval', $requete->fetchAll(PDO::FETCH_COLUMN));
                if ($ids !== []) {
                    return $ids;
                }
            } catch (PDOException) {
                // Index absent ou illisible : le repli LIKE ci-dessous répond, au même périmètre.
            }
        }

        $clauses = [];
        $like = [...$params];
        foreach ($termes !== [] ? $termes : [mb_strtolower($recherche)] as $terme) {
            $clauses[] = '(d.titre LIKE ? OR d.meta LIKE ?)';
            $motif = '%' . self::echapperLike($terme) . '%';
            $like[] = $motif;
            $like[] = $motif;
        }
        $requete = $this->pdo->prepare('SELECT d.id FROM documents d WHERE ' . $where . ' AND ' . implode(' AND ', $clauses)
            . ' ORDER BY d.date_document DESC, d.id DESC LIMIT ' . self::RECHERCHE_MAX);
        $requete->execute($like);

        return array_map('intval', $requete->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Termes exploitables : minuscules, uniques, au moins deux caractères, bornés. Le jeu restreint de
     * caractères empêche toute injection dans l'expression BOOLEAN MODE.
     *
     * @return list<string>
     */
    private static function termes(string $recherche): array
    {
        $mots = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower($recherche)) ?: [];
        $mots = array_values(array_unique(array_filter($mots, static fn (string $mot): bool => mb_strlen($mot) >= 2)));

        return array_slice($mots, 0, self::TERMES_MAX);
    }

    private static function echapperLike(string $valeur): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $valeur);
    }

    /** @param list<mixed> $params */
    private function compter(string $where, array $params): int
    {
        $requete = $this->pdo->prepare('SELECT COUNT(*) FROM documents d WHERE ' . $where);
        $requete->execute($params);

        return (int) $requete->fetchColumn();
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function page(string $where, array $params, int $taille, int $decalage): array
    {
        $requete = $this->pdo->prepare('SELECT ' . self::COLONNES . ' FROM documents d WHERE ' . $where
            . ' ORDER BY d.date_document DESC, d.id DESC LIMIT ' . $taille . ' OFFSET ' . max(0, $decalage));
        $requete->execute($params);

        return array_map(fn (array $ligne): array => $this->resume($ligne), $requete->fetchAll());
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function parIdentifiants(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marques = implode(', ', array_fill(0, count($ids), '?'));
        $requete = $this->pdo->prepare('SELECT ' . self::COLONNES . ' FROM documents d WHERE d.id IN (' . $marques . ')');
        $requete->execute($ids);
        $parId = [];
        foreach ($requete->fetchAll() as $ligne) {
            $parId[(int) $ligne['id']] = $ligne;
        }
        $documents = [];
        foreach ($ids as $id) {
            if (isset($parId[$id])) {
                $documents[] = $this->resume($parId[$id]);
            }
        }

        return $documents;
    }

    private const COLONNES = 'd.id, d.chemin_relatif, d.nom, d.titre, d.type, d.emetteur, d.date_document, d.montant, d.etiquettes, d.mime, d.taille, d.statut, d.empreinte';

    /** @return array<string, mixed>|null */
    private function ligneVivante(int $id): ?array
    {
        $requete = $this->pdo->prepare('SELECT ' . self::COLONNES . ' FROM documents d WHERE d.id = ?'
            . ' AND d.supprime_le IS NULL AND d.absent_depuis IS NULL LIMIT 1');
        $requete->execute([$id]);
        $ligne = $requete->fetch();

        return $ligne === false ? null : $ligne;
    }

    /**
     * @param array<string, mixed> $ligne
     * @return array<string, mixed>
     */
    private function resume(array $ligne): array
    {
        $etiquettes = json_decode((string) ($ligne['etiquettes'] ?? 'null'), true);

        return [
            'id' => (int) $ligne['id'],
            'titre' => (string) $ligne['titre'],
            'nom' => (string) $ligne['nom'],
            'type' => $ligne['type'] === null ? null : (string) $ligne['type'],
            'emetteur' => $ligne['emetteur'] === null ? null : (string) $ligne['emetteur'],
            'date' => $ligne['date_document'] === null ? null : (string) $ligne['date_document'],
            'montant' => is_numeric($ligne['montant'] ?? null) ? (float) $ligne['montant'] : null,
            'etiquettes' => is_array($etiquettes) ? array_values(array_map('strval', $etiquettes)) : [],
            'mime' => (string) $ligne['mime'],
            'taille' => (int) $ligne['taille'],
            'droits' => $this->niveauPourChemin((string) $ligne['chemin_relatif']),
        ];
    }

    /** @return list<array{numero:int, empreinte:string, taille:int, cree_le:string}> */
    private function versions(int $id): array
    {
        $requete = $this->pdo->prepare('SELECT numero, empreinte, taille, cree_le FROM document_versions WHERE document_id = ? ORDER BY numero');
        $requete->execute([$id]);

        return array_map(static fn (array $v): array => [
            'numero' => (int) $v['numero'], 'empreinte' => (string) $v['empreinte'],
            'taille' => (int) $v['taille'], 'cree_le' => (string) $v['cree_le'],
        ], $requete->fetchAll());
    }

    private function niveauPourChemin(string $chemin): string
    {
        if ($this->administrateur) {
            return 'ecrire';
        }
        $dossier = self::dossierDe($chemin);
        if (isset($this->niveaux[$dossier])) {
            return $this->niveaux[$dossier];
        }
        $id = $this->droits->dossierPourChemin($chemin);

        return $this->niveaux[$dossier] = $id !== null && $this->droits->autorise($this->compte, $id, 'modifier') ? 'ecrire' : 'lire';
    }

    private static function dossierDe(string $chemin): string
    {
        $position = strrpos($chemin, '/');

        return $position === false ? '' : substr($chemin, 0, $position);
    }

    /** @throws ErreurApi */
    private function exigerDroitLecture(int $id, string $etape): void
    {
        if ($this->administrateur) {
            return;
        }
        $dossier = $this->droits->dossierPourDocument($id);
        if ($dossier === null || !$this->droits->autorise($this->compte, $dossier, 'lire')) {
            throw new ErreurApi(403, "droit lire insuffisant sur le document $id", $etape);
        }
    }

    /** @throws ErreurApi */
    private function exigerDroitModification(int $id, string $etape): void
    {
        if ($this->administrateur) {
            return;
        }
        $dossier = $this->droits->dossierPourDocument($id);
        if ($dossier === null || !$this->droits->autorise($this->compte, $dossier, 'modifier')) {
            throw new ErreurApi(403, "droit modifier insuffisant sur le document $id", $etape);
        }
    }

    /**
     * Valide et normalise les métadonnées reçues : une clé inconnue est refusée (jamais ignorée en silence),
     * une date réelle est exigée, les étiquettes sont des chaînes bornées.
     *
     * @param array<string, mixed> $metadonnees
     * @return array<string, mixed>
     * @throws ErreurApi
     */
    private function validerMetadonnees(array $metadonnees, string $etape): array
    {
        if ($metadonnees === []) {
            throw new ErreurApi(400, 'aucune métadonnée à modifier', $etape);
        }
        foreach (array_keys($metadonnees) as $cle) {
            if (!in_array($cle, self::METADONNEES, true)) {
                throw new ErreurApi(400, "métadonnée inconnue : « $cle » (acceptées : " . implode(', ', self::METADONNEES) . ')', $etape);
            }
        }

        $propre = [];
        if (array_key_exists('titre', $metadonnees)) {
            $titre = trim((string) $metadonnees['titre']);
            if ($titre === '' || mb_strlen($titre) > 512) {
                throw new ErreurApi(400, 'titre : vide ou plus de 512 caractères', $etape);
            }
            $propre['titre'] = $titre;
        }
        foreach (['type' => 64, 'emetteur' => 255] as $cle => $longueur) {
            if (array_key_exists($cle, $metadonnees)) {
                $valeur = $metadonnees[$cle] === null ? null : trim((string) $metadonnees[$cle]);
                if ($valeur !== null && mb_strlen($valeur) > $longueur) {
                    throw new ErreurApi(400, "$cle : plus de $longueur caractères", $etape);
                }
                $propre[$cle] = $valeur === '' ? null : $valeur;
            }
        }
        if (array_key_exists('date', $metadonnees)) {
            $date = $metadonnees['date'] === null ? null : trim((string) $metadonnees['date']);
            if ($date !== null && $date !== '' && preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $date, $m) !== 1) {
                throw new ErreurApi(400, "date : « $date » n’est pas une date AAAA-MM-JJ", $etape);
            }
            if (is_string($date) && $date !== '' && !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new ErreurApi(400, "date : « $date » n’existe pas", $etape);
            }
            $propre['date'] = $date === '' ? null : $date;
        }
        if (array_key_exists('montant', $metadonnees)) {
            $montant = $metadonnees['montant'];
            if ($montant !== null && $montant !== '' && !is_numeric($montant)) {
                throw new ErreurApi(400, 'montant : valeur non numérique', $etape);
            }
            $propre['montant'] = $montant === null || $montant === '' ? null : (float) $montant;
        }
        if (array_key_exists('etiquettes', $metadonnees)) {
            if (!is_array($metadonnees['etiquettes'])) {
                throw new ErreurApi(400, 'etiquettes : liste de chaînes attendue', $etape);
            }
            $propre['etiquettes'] = [];
            foreach ($metadonnees['etiquettes'] as $etiquette) {
                $etiquette = trim((string) $etiquette);
                if ($etiquette === '' || mb_strlen($etiquette) > 64) {
                    throw new ErreurApi(400, 'etiquettes : une étiquette vide ou plus de 64 caractères', $etape);
                }
                if (!in_array($etiquette, $propre['etiquettes'], true)) {
                    $propre['etiquettes'][] = $etiquette;
                }
            }
        }
        if (array_key_exists('dossier', $metadonnees)) {
            $dossier = trim(str_replace('\\', '/', (string) $metadonnees['dossier']), '/');
            if ($dossier === '' || str_contains($dossier, '..')) {
                throw new ErreurApi(400, 'dossier : chemin vide ou hors du fonds', $etape);
            }
            $propre['dossier'] = $dossier;
        }

        return $propre;
    }
}
