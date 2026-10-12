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
    private const EMETTEURS_MAX = 1000;

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
        } catch (\RuntimeException $e) {
            // Nomenclature illisible : on rend la liste livrée par défaut, jamais une liste vide muette,
            // et la cause technique part au journal.
            error_log('API documents : nomenclature illisible — ' . $e->getMessage());
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
            . " AND d.emetteur IS NOT NULL AND d.emetteur <> '' ORDER BY d.emetteur LIMIT " . self::EMETTEURS_MAX);
        $requete->execute($params);

        return array_values(array_map('strval', $requete->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Filtres neutres (aucune contrainte), à la forme attendue par `wherePourFiltres`.
     *
     * Le vocabulaire suit les conventions GEDv2 (français) ; la correspondance fonctionnelle avec les
     * paramètres Paperless est documentée dans tests/php/Parite/ApiFiltresDocumentsTest.php.
     *
     * @return array<string, mixed>
     */
    private function filtresVides(): array
    {
        return [
            'type' => null, 'emetteur' => null, 'etiquette' => [], 'date_debut' => null, 'date_fin' => null,
            'dossier' => null,
            'id' => null, 'ids' => [],
            'titre_egal' => null, 'titre_contient' => null, 'titre_commence' => null, 'titre_finit' => null,
            'contenu_egal' => null, 'contenu_contient' => null, 'contenu_commence' => null, 'contenu_finit' => null,
            'titre_contenu' => null,
            'empreinte' => null, 'empreinte_egal' => null, 'mime' => null, 'mime_contient' => null,
            'date_min' => null, 'date_max' => null, 'date_apres' => null, 'date_avant' => null,
            'ajoute_min' => null, 'ajoute_max' => null, 'ajoute_apres' => null, 'ajoute_avant' => null,
            'modifie_min' => null, 'modifie_max' => null, 'modifie_apres' => null, 'modifie_avant' => null,
            'etiquettes_any' => [], 'sans_etiquettes' => [], 'etiquettes_presentes' => null,
            'a_traiter' => null, 'sans_emetteur' => null, 'sans_type' => null, 'sans_dossier' => null,
        ];
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
        } catch (\RuntimeException $e) {
            error_log('API documents : registre des vues illisible — ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Filtres normalisés : une date, un identifiant ou un booléen invalide est refusé 400 (jamais un
     * filtre lâche silencieux). Les filtres de texte sont bornés et échappés pour le LIKE.
     *
     * @param array<string, mixed> $criteres
     * @return array<string, mixed>
     * @throws ErreurApi
     */
    private function criteres(array $criteres, string $etape): array
    {
        $filtres = $this->filtresVides();

        foreach ((array) ($criteres['etiquette'] ?? []) as $etiquette) {
            $etiquette = trim((string) $etiquette);
            if ($etiquette !== '' && !in_array($etiquette, $filtres['etiquette'], true)) {
                $filtres['etiquette'][] = $etiquette;
            }
        }
        $dossier = trim(str_replace('\\', '/', (string) ($criteres['dossier'] ?? '')), '/');
        if (str_contains($dossier, '..')) {
            throw new ErreurApi(400, "filtre dossier invalide : « $dossier »", $etape);
        }

        $filtres['type'] = $this->chaine($criteres['type'] ?? null);
        $filtres['emetteur'] = $this->chaine($criteres['emetteur'] ?? null);
        $filtres['date_debut'] = $this->date($criteres['date_debut'] ?? null, 'date_debut', $etape);
        $filtres['date_fin'] = $this->date($criteres['date_fin'] ?? null, 'date_fin', $etape);
        $filtres['dossier'] = $dossier === '' ? null : $dossier;

        $filtres['id'] = $this->identifiant($criteres['id'] ?? null, 'id', $etape);
        $filtres['ids'] = $this->identifiants($criteres['ids'] ?? null, 'ids', $etape);

        foreach (['titre', 'contenu'] as $champ) {
            foreach (['egal', 'contient', 'commence', 'finit'] as $operateur) {
                $cle = $champ . '_' . $operateur;
                $filtres[$cle] = $this->chaine($criteres[$cle] ?? null);
            }
        }
        $filtres['titre_contenu'] = $this->chaine($criteres['titre_contenu'] ?? null);
        $filtres['empreinte'] = $this->chaine($criteres['empreinte'] ?? null);
        $filtres['empreinte_egal'] = $this->chaine($criteres['empreinte_egal'] ?? null);
        $filtres['mime'] = $this->chaine($criteres['mime'] ?? null);
        $filtres['mime_contient'] = $this->chaine($criteres['mime_contient'] ?? null);

        foreach (['date' => 'date', 'ajoute' => 'horodatage', 'modifie' => 'horodatage'] as $prefixe => $nature) {
            foreach (['min', 'max', 'apres', 'avant'] as $borne) {
                $cle = $prefixe . '_' . $borne;
                $filtres[$cle] = $nature === 'date'
                    ? $this->date($criteres[$cle] ?? null, $cle, $etape)
                    : $this->horodatage($criteres[$cle] ?? null, $cle, $etape);
            }
        }

        $filtres['etiquettes_any'] = $this->listeChaines($criteres['etiquettes_any'] ?? null);
        $filtres['sans_etiquettes'] = $this->listeChaines($criteres['sans_etiquettes'] ?? null);
        foreach (['etiquettes_presentes', 'a_traiter', 'sans_emetteur', 'sans_type', 'sans_dossier'] as $cle) {
            $filtres[$cle] = $this->booleen($criteres[$cle] ?? null, $cle, $etape);
        }

        return $filtres;
    }

    private function chaine(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }

    /** @throws ErreurApi */
    private function identifiant(mixed $valeur, string $nom, string $etape): ?int
    {
        $valeur = trim((string) $valeur);
        if ($valeur === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $valeur) !== 1 || (int) $valeur < 1) {
            throw new ErreurApi(400, "filtre $nom : « $valeur » n’est pas un identifiant positif", $etape);
        }

        return (int) $valeur;
    }

    /**
     * @return list<int>
     * @throws ErreurApi
     */
    private function identifiants(mixed $valeur, string $nom, string $etape): array
    {
        $ids = [];
        foreach ($this->listeChaines($valeur) as $identifiant) {
            if (preg_match('/^\d+$/', $identifiant) !== 1 || (int) $identifiant < 1) {
                throw new ErreurApi(400, "filtre $nom : « $identifiant » n’est pas un identifiant positif", $etape);
            }
            $ids[] = (int) $identifiant;
        }

        return $ids;
    }

    /** @return list<string> */
    private function listeChaines(mixed $valeur): array
    {
        $valeurs = [];
        foreach (is_array($valeur) ? $valeur : explode(',', (string) $valeur) as $element) {
            $element = trim((string) $element);
            if ($element !== '' && !in_array($element, $valeurs, true)) {
                $valeurs[] = $element;
            }
        }

        return $valeurs;
    }

    /** @throws ErreurApi */
    private function booleen(mixed $valeur, string $nom, string $etape): ?bool
    {
        $valeur = trim((string) $valeur);
        if ($valeur === '') {
            return null;
        }
        if (in_array($valeur, ['1', 'true', 'oui', 'vrai'], true)) {
            return true;
        }
        if (in_array($valeur, ['0', 'false', 'non', 'faux'], true)) {
            return false;
        }
        throw new ErreurApi(400, "filtre $nom : « $valeur » n’est pas un booléen (1/0)", $etape);
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

    /** Horodatage accepté en AAAA-MM-JJ (minuit) ou AAAA-MM-JJ HH:MM:SS. @throws ErreurApi */
    private function horodatage(mixed $valeur, string $nom, string $etape): ?string
    {
        $valeur = trim((string) $valeur);
        if ($valeur === '') {
            return null;
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $valeur, $m) === 1) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new ErreurApi(400, "filtre $nom : « $valeur » n’existe pas", $etape);
            }

            return $valeur . ' 00:00:00';
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$#', $valeur, $m) === 1) {
            $secondes = $m[6] ?? '00';
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $secondes > 59) {
                throw new ErreurApi(400, "filtre $nom : « $valeur » n’est pas un horodatage valide", $etape);
            }

            return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $secondes);
        }

        throw new ErreurApi(400, "filtre $nom : « $valeur » n’est pas un horodatage AAAA-MM-JJ[ HH:MM:SS]", $etape);
    }

    /**
     * Clause WHERE commune à la liste, à la recherche et aux référentiels. Aucune valeur n'est
     * concaténée : tout passe par des marqueurs `?` et des paramètres liés.
     *
     * @param array<string, mixed> $filtres
     * @return array{0:string, 1:list<mixed>}
     */
    private function wherePourFiltres(array $filtres): array
    {
        $clauses = ['d.supprime_le IS NULL', 'd.absent_depuis IS NULL'];
        $params = [];

        if (($filtres['id'] ?? null) !== null) {
            $clauses[] = 'd.id = ?';
            $params[] = $filtres['id'];
        }
        if (($filtres['ids'] ?? []) !== []) {
            $clauses[] = 'd.id IN (' . implode(', ', array_fill(0, count($filtres['ids']), '?')) . ')';
            $params = [...$params, ...$filtres['ids']];
        }
        foreach (['type' => 'BINARY d.type = ?', 'emetteur' => 'BINARY d.emetteur = ?'] as $cle => $operateur) {
            if (($filtres[$cle] ?? null) !== null) {
                $clauses[] = $operateur;
                $params[] = $filtres[$cle];
            }
        }
        if (($filtres['date_debut'] ?? null) !== null) {
            $clauses[] = 'd.date_document >= ?';
            $params[] = $filtres['date_debut'];
        }
        if (($filtres['date_fin'] ?? null) !== null) {
            $clauses[] = 'd.date_document <= ?';
            $params[] = $filtres['date_fin'];
        }

        // Titre et contenu indexé : mêmes opérateurs que DocumentFilterSet (egal / contient / commence / finit).
        foreach (['egal', 'contient', 'commence', 'finit'] as $operateur) {
            if (($filtres['titre_' . $operateur] ?? null) !== null) {
                $clauses[] = $operateur === 'egal' ? 'd.titre = ?' : 'd.titre LIKE ?';
                $params[] = self::motif($operateur, (string) $filtres['titre_' . $operateur]);
            }
            if (($filtres['contenu_' . $operateur] ?? null) !== null) {
                $clauses[] = self::existeContenu($operateur);
                $params[] = self::motif($operateur, (string) $filtres['contenu_' . $operateur]);
            }
        }
        if (($filtres['titre_contenu'] ?? null) !== null) {
            $motif = self::motif('contient', (string) $filtres['titre_contenu']);
            $clauses[] = '(d.titre LIKE ? OR ' . self::existeContenu('contient') . ')';
            $params[] = $motif;
            $params[] = $motif;
        }
        foreach (['empreinte' => '=', 'empreinte_egal' => '=', 'mime' => '=', 'mime_contient' => 'LIKE'] as $cle => $operateur) {
            if (($filtres[$cle] ?? null) === null) {
                continue;
            }
            $colonne = str_starts_with($cle, 'mime') ? 'd.mime' : 'd.empreinte';
            $clauses[] = $colonne . ' ' . $operateur . ' ?';
            $params[] = $operateur === 'LIKE' ? '%' . self::echapperLike((string) $filtres[$cle]) . '%' : $filtres[$cle];
        }

        foreach ([
            'date_min' => 'd.date_document >= ?', 'date_max' => 'd.date_document <= ?',
            'date_apres' => 'd.date_document > ?', 'date_avant' => 'd.date_document < ?',
            'ajoute_min' => 'd.cree_le >= ?', 'ajoute_max' => 'd.cree_le <= ?',
            'ajoute_apres' => 'd.cree_le > ?', 'ajoute_avant' => 'd.cree_le < ?',
            'modifie_min' => 'd.maj_le >= ?', 'modifie_max' => 'd.maj_le <= ?',
            'modifie_apres' => 'd.maj_le > ?', 'modifie_avant' => 'd.maj_le < ?',
        ] as $cle => $operateur) {
            if (($filtres[$cle] ?? null) !== null) {
                $clauses[] = $operateur;
                $params[] = $filtres[$cle];
            }
        }

        $any = [];
        foreach ($filtres['etiquettes_any'] ?? [] as $etiquette) {
            $any[] = 'JSON_CONTAINS(d.etiquettes, ?)';
            $params[] = (string) json_encode($etiquette, JSON_UNESCAPED_UNICODE);
        }
        if ($any !== []) {
            $clauses[] = '(' . implode(' OR ', $any) . ')';
        }
        foreach ($filtres['etiquette'] ?? [] as $etiquette) {
            $clauses[] = 'JSON_CONTAINS(d.etiquettes, ?)';
            $params[] = (string) json_encode($etiquette, JSON_UNESCAPED_UNICODE);
        }
        foreach ($filtres['sans_etiquettes'] ?? [] as $etiquette) {
            $clauses[] = '(d.etiquettes IS NULL OR NOT JSON_CONTAINS(d.etiquettes, ?))';
            $params[] = (string) json_encode($etiquette, JSON_UNESCAPED_UNICODE);
        }

        $clauses = [...$clauses, ...$this->clausesBooleennes($filtres)];

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
     * Filtres booléens (livrés sous forme de clauses fixes, jamais d'entrée concaténée).
     *
     * @param array<string, mixed> $filtres
     * @return list<string>
     */
    private function clausesBooleennes(array $filtres): array
    {
        $clauses = [];
        if (($filtres['etiquettes_presentes'] ?? null) !== null) {
            $clauses[] = $filtres['etiquettes_presentes']
                ? 'd.etiquettes IS NOT NULL AND JSON_LENGTH(d.etiquettes) > 0'
                : '(d.etiquettes IS NULL OR JSON_LENGTH(d.etiquettes) = 0)';
        }
        if (($filtres['a_traiter'] ?? null) !== null) {
            $clauses[] = $filtres['a_traiter'] ? "d.statut = 'a_controler'" : "d.statut <> 'a_controler'";
        }
        if (($filtres['sans_emetteur'] ?? null) !== null) {
            $clauses[] = $filtres['sans_emetteur']
                ? "(d.emetteur IS NULL OR d.emetteur = '')"
                : "(d.emetteur IS NOT NULL AND d.emetteur <> '')";
        }
        if (($filtres['sans_type'] ?? null) !== null) {
            $clauses[] = $filtres['sans_type']
                ? "(d.type IS NULL OR d.type = '')"
                : "(d.type IS NOT NULL AND d.type <> '')";
        }
        if (($filtres['sans_dossier'] ?? null) !== null) {
            $clauses[] = $filtres['sans_dossier'] ? "d.chemin_relatif NOT LIKE '%/%'" : "d.chemin_relatif LIKE '%/%'";
        }

        return $clauses;
    }

    /** Sous-requête corrélée sur le contenu indexé (`recherche_textes`), au même document. */
    private static function existeContenu(string $operateur): string
    {
        $condition = $operateur === 'egal' ? 'rt.contenu = ?' : 'rt.contenu LIKE ?';

        return 'EXISTS (SELECT 1 FROM recherche_textes rt WHERE rt.document_id = d.id AND rt.absent_le IS NULL AND ' . $condition . ')';
    }

    /** Motif LIKE (échappé) selon l'opérateur ; l'égalité rend la valeur nue. */
    private static function motif(string $operateur, string $valeur): string
    {
        $echappe = self::echapperLike($valeur);

        return match ($operateur) {
            'contient' => '%' . $echappe . '%',
            'commence' => $echappe . '%',
            'finit' => '%' . $echappe,
            default => $valeur,
        };
    }

    /**
     * Identifiants de la recherche, dans l'ordre d'affichage, bornés et AU MÊME périmètre de droits.
     *
     * @param array<string, mixed> $filtres
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
            } catch (PDOException $e) {
                // Index absent ou illisible : le repli LIKE ci-dessous répond, au même périmètre ; la
                // cause technique part au journal (repli documenté, jamais un échec muet).
                error_log('API documents : index plein texte indisponible — ' . $e->getMessage());
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
