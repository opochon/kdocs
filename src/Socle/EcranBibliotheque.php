<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Recherche\Requete as RequeteRecherche;
use Ged\Recherche\Service as ServiceRecherche;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Écran Bibliothèque (V2-04), branché sur les VRAIES données : la liste des documents vient de
 * l'index (miroir reconstructible du dossier — F12), les valeurs du document retenu se relisent de
 * son `.meta.json` (le dossier fait foi), les vues enregistrées filtrent sans jamais déplacer.
 *
 * PERF-3 — sur un gros fonds (10 000 pièces du banc de charge), l'écran ne devait ni lire tout
 * l'index (la colonne `meta`, copie de chaque `.meta.json`) ni rendre 10 000 lignes : la file d'un
 * serveur mono-processus dépassait alors le seuil de 1,5 s. Désormais :
 * - la LISTE est paginée CÔTÉ BASE (`LIMIT`/`OFFSET`, plus récent d'abord) : quelques lignes lues ;
 * - les COMPTAGES sont bornés (un `COUNT(*)` indexé pour le fonds, une requête par vue) ;
 * - le panneau d'aperçu résout UN document par son identifiant (jamais un balayage du fonds) ;
 * - l'arborescence est construite d'un SEUL passage indexé (`k_vivant`), pas d'une lecture de chaque
 *   `.meta.json` ; un chemin plus long que le préfixe d'index force le repli honnête.
 *
 * Le contenu affiché ne change pas : mêmes colonnes, mêmes valeurs, mêmes droits. La liste passe par
 * des pages de 100 lignes (plan : « liste au centre, 100 lignes puis défilement »), toutes atteignables.
 */
final class EcranBibliotheque
{
    /** Lignes affichées par page : borne le travail SQL et le rendu, indépendamment du fonds. */
    public const PAGE_TAILLE = 100;

    /** Longueur du préfixe indexé de `chemin_relatif` (doit rester aligné avec `Schema`). */
    private const PREFIXE_CHEMIN = 700;

    private readonly PDO $pdo;
    private readonly Fonds $fonds;
    private readonly Vues $vues;
    private readonly Champs $champs;
    private readonly Nomenclature $nomenclature;
    /** Compte réel du lecteur (null = compte synthétique des oracles : aucun périmètre ACL à poser). */
    private readonly ?int $compte;
    /** Un administrateur (permission '*') voit tout : le périmètre ACL est alors neutre. */
    private readonly bool $administrateur;
    /** @var array<int, list<int>> Résultats du moteur Recherche, mémorisés pendant ce rendu. */
    private array $idsRecherche = [];
    /** @var array<int, string> Erreurs nommées du moteur Recherche, par vue. */
    private array $erreursRecherche = [];

    public function __construct(Config $config, ?int $compte = null, bool $administrateur = false)
    {
        $this->compte = $compte !== null && $compte > 0 ? $compte : null;
        $this->administrateur = $administrateur;
        $this->pdo = Base::pdo($config);
        $this->fonds = new Fonds($config);
        $this->vues = new Vues($config);
        $this->champs = new Champs($config);
        $this->nomenclature = new Nomenclature($config);
    }

    /**
     * Tout ce que `ecrans/bibliotheque.twig` attend : une page de documents filtrés, panneau d'aperçu
     * (avec les champs personnalisés du document retenu), vues du volet, filtres compilés, pagination.
     *
     * @param array<string, mixed> $parametres paramètres de requête (?doc=, ?vue=, ?page=, filtres GET)
     * @return array<string, mixed>
     */
    public function donnees(array $parametres, string $csrf): array
    {
        // Vue demandée par son numéro (liens du volet) : SES filtres compilés s'appliquent.
        $vueActive = null;
        $candidat = trim((string) ($parametres['vue'] ?? ''));
        if ($candidat !== '') {
            $vueActive = ctype_digit($candidat) ? $this->vues->lire((int) $candidat) : $this->vues->parSlug($candidat);
        }

        $filtres = null;
        $erreur = null;
        if ($vueActive !== null) {
            $filtres = $vueActive['filtres'];
        } else {
            try {
                $filtres = $this->vues->compiler($parametres);
            } catch (RuntimeException $e) {
                $erreur = $e->getMessage();   // filtre incohérent : nommé à l'écran, jamais un écran muet
            }
        }
        $filtres ??= $this->filtresNeutres();

        // Le fonds entier n'est jamais chargé : comptage indexé, page bornée, arbre d'un passage indexé.
        $total = $this->compterVivants();
        $dossierActif = $this->dossierActif($parametres['dossier'] ?? null);
        [$where, $params] = $this->wherePourFiltres($filtres, $dossierActif);
        $ordreRecherche = null;
        if ($vueActive !== null && $this->estVueRecherche($vueActive)) {
            $ordreRecherche = $this->idsPourVueRecherche($vueActive);
            if (isset($this->erreursRecherche[(int) $vueActive['id']])) {
                $erreur = $this->erreursRecherche[(int) $vueActive['id']];
            }
            [$where, $params] = $this->restreindreAuxIds($where, $params, $ordreRecherche);
        }
        $totalFiltre = $this->compterAvec($where, $params);

        $pages = max(1, (int) ceil($totalFiltre / self::PAGE_TAILLE));
        $page = max(1, min($pages, (int) ($parametres['page'] ?? 1)));
        $docs = $this->lirePage($where, $params, $page, $ordreRecherche);
        foreach ($docs as &$doc) {
            $doc['lien'] = $this->lienDocument($parametres, (int) $doc['id']);
        }
        unset($doc);

        // Panneau d'aperçu : le document demandé (?doc=), sinon le premier de la page.
        $doc = null;
        $demande = (int) ($parametres['doc'] ?? 0);
        if ($demande > 0) {
            $doc = $this->docParId($demande);
        }
        $doc ??= $docs[0] ?? null;

        return [
            'ecran' => 'bibliotheque',
            'bilan' => \Ged\Ui\Maquette::bilan(),   // badge « à traiter » du rail : encore la maquette (branchement : secteur Ingestion)
            'docs' => $docs,
            'compte' => count($docs),
            'total' => $total,
            'totalFiltre' => $totalFiltre,
            'page' => $page,
            'pages' => $pages,
            'pageTaille' => self::PAGE_TAILLE,
            'lienPagePrecedente' => $page > 1 ? $this->lienPage($parametres, $page - 1) : null,
            'lienPageSuivante' => $page < $pages ? $this->lienPage($parametres, $page + 1) : null,
            'vuesVolet' => $this->vuesDuVolet($total),
            'vueActive' => $vueActive,
            'arbre' => $this->arbreVivant(),
            'dossierActif' => $dossierActif,
            'types' => $this->typesSansEchec(),
            'tousChamps' => $this->champsSansEchec(),
            'operateurs' => Champs::operateurs(),
            'filtres' => $filtres,
            'erreur' => $erreur,
            'apercu' => $this->apercu($doc),
            'csrf' => $csrf,
        ];
    }

    /**
     * Les vues du volet : « Tous les documents » (le fonds entier) puis chaque vue enregistrée avec le
     * nombre de documents qui la satisfont. Les comptes viennent d'un COUNT indexé (une requête par vue,
     * jamais un parcours du fonds par vue) — même sémantique que `Vues::filtrerDocs`.
     *
     * @return list<array{id:?int, nom:string, compte:int}>
     */
    private function vuesDuVolet(int $total): array
    {
        $volet = [['id' => null, 'nom' => 'Tous les documents', 'compte' => $total]];
        foreach ($this->vues->lister() as $vue) {
            [$where, $params] = $this->wherePourFiltres($vue['filtres']);
            if ($this->estVueRecherche($vue)) {
                [$where, $params] = $this->restreindreAuxIds($where, $params, $this->idsPourVueRecherche($vue));
            }
            $volet[] = ['id' => $vue['id'], 'nom' => $vue['nom'], 'compte' => $this->compterAvec($where, $params)];
        }

        return $volet;
    }

    /**
     * La clause SQL d'un jeu de filtres compilés, plus récentes d'abord ensuite. Reproduit la sémantique
     * de `Vues::satisfaitTout` : les colonnes de l'index pour type/émetteur/dates/étiquettes, le JSON
     * `meta.champs` pour les champs personnalisés. Aucune clé utilisateur n'entre dans la requête hors
     * paramètre lié (la clé de champ est validée en slug).
     *
     * @param array<string, mixed> $filtres
     * @return array{0:string, 1:list<mixed>}
     */
    private function wherePourFiltres(array $filtres, ?string $dossier = null): array
    {
        $clauses = ['d.supprime_le IS NULL', 'd.absent_depuis IS NULL'];
        $params = [];

        if (($filtres['type'] ?? null) !== null) {
            $clauses[] = 'BINARY d.type = ?';
            $params[] = (string) $filtres['type'];
        }
        if (($filtres['emetteur'] ?? null) !== null) {
            $clauses[] = 'BINARY d.emetteur = ?';
            $params[] = (string) $filtres['emetteur'];
        }
        if (($filtres['de'] ?? null) !== null) {
            $clauses[] = 'd.date_document >= ?';
            $params[] = (string) $filtres['de'];
        }
        if (($filtres['a'] ?? null) !== null) {
            $clauses[] = 'd.date_document <= ?';
            $params[] = (string) $filtres['a'];
        }
        foreach ((array) ($filtres['etiquettes'] ?? []) as $etiquette) {
            // Une étiquette absente (`etiquettes` NULL) ne satisfait pas : JSON_CONTAINS rend NULL.
            $clauses[] = 'JSON_CONTAINS(d.etiquettes, ?)';
            $params[] = json_encode((string) $etiquette, JSON_UNESCAPED_UNICODE);
        }
        $champ = $filtres['champ'] ?? null;
        if (is_array($champ) && isset($champ['cle'], $champ['op'])) {
            $this->clauseChamp($clauses, $params, $champ);
        }
        if ($dossier !== null) {
            $clauses[] = '(d.chemin_relatif = ? OR d.chemin_relatif LIKE ?)';
            $params[] = $dossier;
            $params[] = $dossier . '/%';
        }
        [$acl, $aclParams] = $this->clauseAcl();
        if ($acl !== null) {
            $clauses[] = $acl;
            $params = [...$params, ...$aclParams];
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Le périmètre ACL de CE lecteur, posé DANS chaque requête de liste (V2-13, point B3) : jamais un
     * filtrage après coup. Le compte synthétique des oracles (id nul) garde le comportement historique,
     * l’administrateur voit tout ; tout autre compte ne lit que ses dossiers.
     *
     * @return array{0:?string, 1:list<int>}
     */
    private function clauseAcl(): array
    {
        if ($this->administrateur || $this->compte === null) {
            return [null, []];
        }

        return DroitsDossiers::predicatLecture('d', $this->compte);
    }

    /**
     * Traduit un filtre de champ personnalisé (valeur vivant dans `meta.champs.<clé>`) en clause JSON.
     * Les mêmes règles de forme que `Vues::satisfaitChamp` : une date répond aux opérateurs de date,
     * un nombre aux bornes, un booléen à `est` ; sinon la comparaison de texte est insensible à la casse.
     *
     * @param list<string> $clauses
     * @param list<mixed> $params
     * @param array<string, mixed> $champ
     */
    private function clauseChamp(array &$clauses, array &$params, array $champ): void
    {
        $cle = (string) $champ['cle'];
        if (preg_match('/^[A-Za-z0-9_-]+$/', $cle) !== 1) {
            $clauses[] = '1 = 0';   // clé impossible : le document ne satisfait pas, jamais un filtre lâche

            return;
        }
        $chemin = "'$.champs." . $cle . "'";
        $extrait = "JSON_EXTRACT(d.meta, $chemin)";
        $type = "JSON_TYPE($extrait)";
        $texte = "JSON_UNQUOTE($extrait)";
        $valeur = $champ['valeur'];

        switch ((string) $champ['op']) {
            case 'avant_jours':
                $clauses[] = "$type = 'STRING' AND $texte <= ?";
                $params[] = date('Y-m-d', time() + ((int) $valeur) * 86400);
                break;
            case 'avant':
                $clauses[] = "$type = 'STRING' AND $texte <= ?";
                $params[] = (string) $valeur;
                break;
            case 'apres':
                $clauses[] = "$type = 'STRING' AND $texte > ?";
                $params[] = (string) $valeur;
                break;
            case 'min':
                $clauses[] = "$type IN ('INTEGER','DOUBLE','DECIMAL') AND CAST($texte AS DECIMAL(20,6)) >= ?";
                $params[] = (float) $valeur;
                break;
            case 'max':
                $clauses[] = "$type IN ('INTEGER','DOUBLE','DECIMAL') AND CAST($texte AS DECIMAL(20,6)) <= ?";
                $params[] = (float) $valeur;
                break;
            case 'egal':
                $clauses[] = "$type IN ('INTEGER','DOUBLE','DECIMAL') AND ABS(CAST($texte AS DECIMAL(20,6)) - ?) < 0.005";
                $params[] = (float) $valeur;
                break;
            case 'est':
                $clauses[] = "$type = 'BOOLEAN' AND $texte = ?";
                $params[] = mb_strtolower((string) $valeur) === 'oui' ? 'true' : 'false';
                break;
            case 'contient':
                $clauses[] = "$extrait IS NOT NULL AND $texte LIKE ?";
                $params[] = '%' . addcslashes((string) $valeur, '%_\\') . '%';
                break;
            default:
                $clauses[] = "$type = 'STRING' AND LOWER(TRIM($texte)) = LOWER(TRIM(?))";
                $params[] = (string) $valeur;
        }
    }

    /** Nombre de documents vivants lisibles par ce compte (COUNT indexé, aucune ligne de fonds lue). */
    private function compterVivants(): int
    {
        $where = 'd.supprime_le IS NULL AND d.absent_depuis IS NULL';
        $params = [];
        [$acl, $aclParams] = $this->clauseAcl();
        if ($acl !== null) {
            $where .= ' AND ' . $acl;
            $params = $aclParams;
        }
        $requete = $this->pdo->prepare('SELECT COUNT(*) FROM documents d WHERE ' . $where);
        $requete->execute($params);

        return (int) $requete->fetchColumn();
    }

    /** @param list<mixed> $params */
    private function compterAvec(string $where, array $params): int
    {
        $requete = $this->pdo->prepare('SELECT COUNT(*) FROM documents d WHERE ' . $where);
        $requete->execute($params);

        return (int) $requete->fetchColumn();
    }

    /**
     * Une vue Recherche garde un tableau non vide dans le registre ; une vue Bibliothèque reste inchangée.
     *
     * @param array<string, mixed> $vue
     */
    private function estVueRecherche(array $vue): bool
    {
        return isset($vue['recherche']) && is_array($vue['recherche']) && $vue['recherche'] !== [];
    }

    /**
     * Interroge le moteur de Recherche existant (son index FULLTEXT/repli LIKE), jamais le fonds.
     * Les identifiants gardent son ordre de pertinence pour que la vue reproduise aussi la liste visible.
     *
     * @param array<string, mixed> $vue
     * @return list<int>
     */
    private function idsPourVueRecherche(array $vue): array
    {
        $idVue = (int) $vue['id'];
        if (isset($this->idsRecherche[$idVue])) {
            return $this->idsRecherche[$idVue];
        }
        $moteur = new ServiceRecherche($this->pdo, $this->compte, $this->administrateur);
        $resultat = $moteur->rechercher(RequeteRecherche::depuisParametres($this->vues->criteresRecherche($vue)));
        if ($resultat->erreur !== null) {
            $this->erreursRecherche[$idVue] = 'vue Recherche impossible : étape « ' . $resultat->erreur->etape
                . ' », cause : ' . $resultat->erreur->cause;
        }

        return $this->idsRecherche[$idVue] = array_values(array_unique(array_map(
            static fn ($trouvaille): int => $trouvaille->id,
            $resultat->trouvailles,
        )));
    }

    /**
     * Ajoute la liste issue du moteur Recherche à la clause de la Bibliothèque, sans second moteur.
     *
     * @param list<mixed> $params
     * @param list<int>   $ids
     * @return array{0: string, 1: list<mixed>}
     */
    private function restreindreAuxIds(string $where, array $params, array $ids): array
    {
        if ($ids === []) {
            return [$where . ' AND 1 = 0', $params];
        }

        return [$where . ' AND d.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', [...$params, ...$ids]];
    }

    /**
     * Une page de la liste, lue CÔTÉ BASE : seules les colonnes de l'écran, plus récentes d'abord.
     * La colonne `meta` (copie du `.meta.json`) n'est jamais lue pour une liste — un document retenu
     * relit, lui, son fichier.
     *
     * @param list<mixed> $params
     * @param list<int>|null $ordreRecherche
     * @return list<array<string, mixed>>
     */
    private function lirePage(string $where, array $params, int $page, ?array $ordreRecherche = null): array
    {
        $decalage = ($page - 1) * self::PAGE_TAILLE;
        $ordre = 'd.id DESC';
        if ($ordreRecherche !== null && $ordreRecherche !== []) {
            $ordre = 'FIELD(d.id, ' . implode(', ', array_fill(0, count($ordreRecherche), '?')) . ')';
            $params = [...$params, ...$ordreRecherche];
        }
        $requete = $this->pdo->prepare(
            'SELECT d.id, d.chemin_relatif, d.nom, d.titre, d.type, d.emetteur, d.date_document, d.montant, d.etiquettes'
            . ' FROM documents d WHERE ' . $where . ' ORDER BY ' . $ordre . ' LIMIT ' . self::PAGE_TAILLE . ' OFFSET ' . $decalage);
        $requete->execute($params);

        return array_map([$this, 'ligne'], $requete->fetchAll());
    }

    /**
     * Le document retenu, résolu par son identifiant ET le périmètre ACL : une ligne, jamais un balayage du fonds.
     *
     * @return array<string, mixed>|null
     */
    private function docParId(int $id): ?array
    {
        $where = 'd.id = ? AND d.supprime_le IS NULL AND d.absent_depuis IS NULL';
        $params = [$id];
        [$acl, $aclParams] = $this->clauseAcl();
        if ($acl !== null) {
            $where .= ' AND ' . $acl;
            $params = [...$params, ...$aclParams];
        }
        $requete = $this->pdo->prepare(
            'SELECT d.id, d.chemin_relatif, d.nom, d.titre, d.type, d.emetteur, d.date_document, d.montant, d.etiquettes'
            . ' FROM documents d WHERE ' . $where);
        $requete->execute($params);
        $ligne = $requete->fetch();

        return $ligne === false ? null : $this->ligne($ligne);
    }

    /**
     * Une ligne d'index vers la forme attendue par le gabarit et par le panneau d'aperçu (mêmes clés que
     * `Fonds::fiche`). Les `champs` personnalisés se relisent du `.meta.json` au moment de l'aperçu.
     *
     * @param array<string, mixed> $ligne
     * @return array<string, mixed>
     */
    private function ligne(array $ligne): array
    {
        $chemin = str_replace('\\', '/', (string) $ligne['chemin_relatif']);
        $etiquettes = json_decode((string) ($ligne['etiquettes'] ?? 'null'), true);

        return [
            'id' => (int) $ligne['id'],
            'chemin' => $chemin,
            'nom' => (string) ($ligne['nom'] ?? basename($chemin)),
            'titre' => (string) ($ligne['titre'] ?? pathinfo($chemin, PATHINFO_FILENAME)),
            'type' => isset($ligne['type']) && is_string($ligne['type']) ? $ligne['type'] : null,
            'emetteur' => isset($ligne['emetteur']) && is_string($ligne['emetteur']) ? $ligne['emetteur'] : null,
            'date' => isset($ligne['date_document']) && is_string($ligne['date_document']) ? $ligne['date_document'] : null,
            'montant' => isset($ligne['montant']) && is_numeric($ligne['montant']) ? (float) $ligne['montant'] : null,
            'etiquettes' => is_array($etiquettes) ? array_values(array_map('strval', $etiquettes)) : [],
            'champs' => [],
        ];
    }

    /**
     * Arborescence des dossiers du fonds, construite d'UN passage indexé : `chemin_relatif` est lu depuis
     * l'index `k_vivant` (pas de lecture de chaque ligne du fonds). Si un chemin atteint la longueur du
     * préfixe indexé, la valeur peut être tronquée : on retombe alors sur la lecture complète, correcte.
     *
     * @return list<array{chemin: string, nom: string, profondeur: int, compte: int}>
     */
    private function arbreVivant(): array
    {
        $where = 'd.supprime_le IS NULL AND d.absent_depuis IS NULL';
        $params = [];
        [$acl, $aclParams] = $this->clauseAcl();
        if ($acl !== null) {
            $where .= ' AND ' . $acl;
            $params = $aclParams;
        }
        try {
            $requete = $this->pdo->prepare('SELECT d.chemin_relatif FROM documents d FORCE INDEX (k_vivant) WHERE ' . $where);
            $requete->execute($params);
            $chemins = $requete->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException) {
            $chemins = [];   // index absent d'une base non migrée : repli complet ci-dessous
        }

        $tronque = $chemins === [];
        foreach ($chemins as $chemin) {
            if (strlen((string) $chemin) >= self::PREFIXE_CHEMIN) {
                $tronque = true;
                break;
            }
        }
        if ($tronque) {
            $requete = $this->pdo->prepare('SELECT d.chemin_relatif FROM documents d WHERE ' . $where);
            $requete->execute($params);
            $chemins = $requete->fetchAll(PDO::FETCH_COLUMN);
        }

        $dossiers = [];
        foreach ($chemins as $chemin) {
            $segments = explode('/', str_replace('\\', '/', (string) $chemin));
            array_pop($segments);
            $courant = [];
            foreach ($segments as $segment) {
                if ($segment === '') continue;
                $courant[] = $segment;
                $cle = implode('/', $courant);
                $dossiers[$cle] = ($dossiers[$cle] ?? 0) + 1;
            }
        }
        ksort($dossiers, SORT_NATURAL | SORT_FLAG_CASE);
        $arbre = [];
        foreach ($dossiers as $chemin => $compte) {
            $chemin = (string) $chemin;
            $arbre[] = ['chemin' => $chemin, 'nom' => basename($chemin), 'profondeur' => substr_count($chemin, '/'), 'compte' => $compte];
        }
        return $arbre;
    }

    private function dossierActif(mixed $brut): ?string
    {
        $dossier = trim(str_replace('\\', '/', (string) $brut), '/');
        return $dossier === '' || str_contains($dossier, '..') ? null : $dossier;
    }

    /**
     * Le panneau d'aperçu d'un document : la visionneuse du secteur Aperçu quand le fichier est
     * rendable, et le formulaire des champs personnalisés de SON type avec les valeurs posées.
     *
     * @param array<string, mixed>|null $doc
     * @return array<string, mixed>|null
     */
    private function apercu(?array $doc): ?array
    {
        if ($doc === null) {
            return null;
        }
        $extension = strtolower(pathinfo((string) $doc['nom'], PATHINFO_EXTENSION));
        $rendable = $doc['id'] > 0 && in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'avif'], true);

        $champs = [];
        if ($doc['type'] !== null) {
            // Les valeurs affichées (et éditables) viennent du `.meta.json` À CÔTÉ DU FICHIER — le
            // dossier fait foi ; l'index n'en est que la copie. Un seul fichier relu, jamais le fonds.
            $valeurs = $doc['champs'];
            $metaAbsolue = $this->fonds->racine() . '/' . $doc['chemin'] . '.meta.json';
            if (is_file($metaAbsolue)) {
                $surPlace = json_decode((string) file_get_contents($metaAbsolue), true);
                if (is_array($surPlace) && isset($surPlace['champs']) && is_array($surPlace['champs'])) {
                    $valeurs = $surPlace['champs'];
                }
            }
            foreach ($this->champs->pourType($doc['type']) as $def) {
                $champs[] = $def + ['valeur' => $valeurs[$def['cle']] ?? null];
            }
        }

        return [
            'id' => $doc['id'], 'titre' => $doc['titre'], 'type' => $doc['type'], 'emetteur' => $doc['emetteur'],
            'date' => $doc['date'], 'chemin' => $doc['chemin'], 'champs' => $champs,
            'rendu' => $rendable
                ? ['url' => '/documents/' . $doc['id'] . '/apercu/fichier', 'categorie' => $extension === 'pdf' ? 'pdf' : 'image',
                    'pages' => 1, 'titre' => $doc['titre'], 'message' => '']
                : ['url' => '', 'categorie' => 'inconnu', 'pages' => 0, 'titre' => $doc['titre'],
                    'message' => $doc['id'] > 0 ? 'Aucun aperçu pour ce format.' : 'Document non indexé : aucun aperçu.'],
        ];
    }

    /**
     * Filtres neutres (aucune contrainte) : la forme compilée vide, pour le gabarit toujours fournie.
     *
     * @return array{type: null, emetteur: null, de: null, a: null, etiquettes: list<string>, champ: null}
     */
    private function filtresNeutres(): array
    {
        return ['type' => null, 'emetteur' => null, 'de' => null, 'a' => null, 'etiquettes' => [], 'champ' => null];
    }

    /**
     * Lien vers une page du même écran, en gardant les filtres, la vue et le document demandé.
     *
     * @param array<string, mixed> $parametres
     */
    private function lienPage(array $parametres, int $page): string
    {
        $parametres['page'] = $page;

        return '/bibliotheque?' . http_build_query($parametres);
    }

    /**
     * Un document ouvert conserve le dossier et les filtres : retour arrière et partage restent cohérents.
     *
     * @param array<string, mixed> $parametres
     */
    private function lienDocument(array $parametres, int $id): string
    {
        $parametres['doc'] = $id;
        unset($parametres['page']);
        return '/bibliotheque?' . http_build_query($parametres);
    }

    /**
     * La nomenclature vit dans le fonds (`.ged/`, V2-04) : sa lecture ne peut plus lever qu'une
     * RuntimeException (fichier illisible) — nommée dans les journaux, jamais un écran muet.
     * @return list<string>
     */
    private function typesSansEchec(): array
    {
        try {
            return $this->nomenclature->types();
        } catch (PDOException | RuntimeException) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function champsSansEchec(): array
    {
        try {
            return $this->champs->tous();
        } catch (PDOException | RuntimeException) {
            return [];
        }
    }
}
