<?php

declare(strict_types=1);

/**
 * Espace de simulation (lot ged-sim-corpus-1) : ingère le corpus synthétique dans `gedv2_test` par le
 * VRAI Socle (`Ged\Socle\DepotFichiers`), lance le classement automatique (`Ged\Classement\ClasseurGed`
 * en mode règles, sans réseau) et la recherche (`Ged\Recherche\Service`), puis compare à la vérité
 * versionnée `recette/sim/verite-corpus.json`.
 *
 *   php tools/banc/mesurer-sim-corpus.php
 *
 * Le corpus lui-même vit dans `var/sim/` (hors dépôt, régénérable) : s'il manque, le banc le génère
 * avec `CMD4_PYTHON` (PyMuPDF). La vérité régénérée dans `var/sim/` DOIT être identique à celle du
 * dépôt ; sinon le banc s'arrête et le dit (une vérité désynchronisée ne mesure rien).
 *
 * Mesure : taux de classement correct (type/émetteur/date), 0 validé faux ; précision et rappel de la
 * recherche sur chaque requête de la vérité, temps maximal. Preuves : `recette/preuves/sim/corpus.{json,md}`.
 * Code 0 seulement si la porte est franchie.
 */

use Ged\Base;
use Ged\Classement\ClasseurGed;
use Ged\Classement\ClientIaNul;
use Ged\Classement\Habitudes;
use Ged\Classement\Regles;
use Ged\Classement\Schema as SchemaClassement;
use Ged\Config;
use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Requete;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Recherche\Service;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Schema as SchemaSocle;

const SEUIL_TYPE_CORRECT = 0.90;          // taux de type correct sur tout le corpus
const SEUIL_EMETTEURS_VIDES = 0.15;       // part maximale d'émetteurs non lus
const SEUIL_PRECISION = 0.90;             // précision moyenne de la recherche
const SEUIL_RAPPEL = 0.90;                // rappel moyen de la recherche
const BUDGET_MS_PAR_REQUETE = 1000;       // budget de temps par requête

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

// Un seul exécutant sur gedv2_test : même verrou que PHPUnit et les autres bancs.
$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "Verrou de la base de test impossible à prendre (gedv2_test.lock)\n");
    exit(2);
}
putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

$config = new Config($racine);
if ($config->get('DB_NAME') !== 'gedv2_test') {
    throw new RuntimeException('le banc de simulation n’écrit que sur gedv2_test');
}

assurerCorpus($racine, $config);
veriteSynchronisee($racine);

$verite = chargerJson("$racine/recette/sim/verite-corpus.json");
$lectures = chargerJson("$racine/var/sim/lectures.json");
/** @var list<array<string, mixed>> $documents */
$documents = (array) $verite['documents'];
/** @var list<array<string, mixed>> $requetes */
$requetes = (array) $verite['requetes'];
/** @var array<string, string> $texteParId */
$texteParId = [];
foreach ((array) $lectures['documents'] as $lecture) {
    $texteParId[(string) $lecture['id']] = (string) ($lecture['texte'] ?? '');
}

// Fonds de simulation régénérable : le dossier fait foi, il est rangé par le vrai Socle.
$fonds = $racine . '/var/sim/fonds';
viderDossier($fonds);
@mkdir($fonds, 0775, true);
putenv('STORAGE_ROOT=' . $fonds);

Base::assurerBase($config);
$pdo = Base::pdo($config);
reinitialiser($pdo);

$t0 = microtime(true);
$depot = new DepotFichiers($config);
/** @var array<int, string> $idVersSim */
$idVersSim = [];
$erreursDepot = [];
foreach ($documents as $document) {
    $simId = (string) $document['id'];
    $source = $racine . '/' . (string) $document['fichier'];
    if (!is_file($source)) {
        $erreursDepot[] = "$simId : fichier source absent ($source)";
        continue;
    }
    $meta = [
        'titre' => (string) $document['titre'],
        'type' => (string) $document['type'],
        'emetteur' => (string) $document['emetteur'],
        'date' => (string) $document['date'],
        'montant' => $document['montant'] ?? null,
        'texte' => $texteParId[$simId] ?? '',
        'statut' => 'valide',
        'source' => 'simulation',
        'sim_id' => $simId,
        'destinataire' => (string) $document['destinataire'],
    ];
    try {
        $idVersSim[$depot->deposer($source, $meta)] = $simId;
    } catch (Throwable $erreur) {
        $erreursDepot[] = "$simId : " . $erreur->getMessage();
    }
}
$dureeIngestion = round(microtime(true) - $t0, 2);

$documentsIndexes = (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
$posesIndex = IndexPleinTexte::depuis($config, $pdo)->synchroniser(null);

// --- Classement automatique (règles seules : aucune IA, aucun réseau) -----------------------------
$classeur = new ClasseurGed($config, $pdo, new ClientIaNul(), new Habitudes($pdo));
$classement = ['documents' => 0, 'types_ok' => 0, 'emetteurs_ok' => 0, 'emetteurs_vides' => 0,
    'dates_ok' => 0, 'dates_attendues' => 0, 'valides' => 0, 'valides_faux' => 0];
$anomalies = [];
$tClass = microtime(true);
foreach ($documents as $document) {
    $simId = (string) $document['id'];
    $typeAttendu = (string) $document['type'];
    $emetteurAttendu = (string) $document['emetteur'];
    $r = $classeur->proposer($texteParId[$simId] ?? '', [], basename((string) $document['fichier']));
    $typeOk = Regles::memeType($r['type'], $typeAttendu);
    $emetteurLu = ($r['emetteur'] ?? null) !== null && $r['emetteur'] !== '';
    $emetteurOk = $emetteurLu && Regles::normaliser((string) $r['emetteur']) === Regles::normaliser($emetteurAttendu);
    $dateOk = ($document['date'] ?? null) !== null && $r['date'] === $document['date'];
    $valideFaux = (bool) $r['valide'] && (!$typeOk || !$emetteurOk || !$dateOk);

    ++$classement['documents'];
    $classement['types_ok'] += (int) $typeOk;
    $classement['emetteurs_ok'] += (int) $emetteurOk;
    $classement['emetteurs_vides'] += (int) !$emetteurLu;
    $classement['dates_attendues'] += (int) (($document['date'] ?? null) !== null);
    $classement['dates_ok'] += (int) $dateOk;
    $classement['valides'] += (int) $r['valide'];
    $classement['valides_faux'] += (int) $valideFaux;
    if (!$typeOk || !$emetteurOk || !$dateOk) {
        $anomalies[] = ['id' => $simId, 'type_attendu' => $typeAttendu, 'type_propose' => $r['type'],
            'emetteur_attendu' => $emetteurAttendu, 'emetteur_propose' => $r['emetteur'],
            'date_attendue' => $document['date'], 'date_proposee' => $r['date'], 'valide' => $r['valide'],
            'raison' => $r['raison']];
    }
}
$dureeClassement = round(microtime(true) - $tClass, 2);

// --- Recherche : chaque requête de la vérité, précision et rappel ---------------------------------
$moteur = new Service($pdo);
$moteur->rechercher(new Requete(''));   // chauffe : la première requête ne porte pas la mesure
$mesuresRequetes = [];
$sommePrecision = 0.0;
$sommeRappel = 0.0;
$maxMs = 0.0;
$erreursRequetes = [];
foreach ($requetes as $requete) {
    $parametres = array_merge(['q' => (string) ($requete['q'] ?? ''), 'limite' => 200],
        (array) ($requete['filtres'] ?? []));
    $resultat = $moteur->rechercher(Requete::depuisParametres($parametres));
    if ($resultat->erreur !== null) {
        $erreursRequetes[] = (string) $requete['id'] . ' : ' . $resultat->erreur->cause;
    }
    $obtenus = [];
    foreach ($resultat->trouvailles as $trouvaille) {
        $sim = $idVersSim[$trouvaille->id] ?? null;
        if (is_string($sim)) {
            $obtenus[] = $sim;
        }
    }
    $attendus = array_values(array_map('strval', (array) ($requete['attendu'] ?? [])));
    $inter = count(array_intersect($obtenus, $attendus));
    $precision = $obtenus !== [] ? $inter / count($obtenus) : 0.0;
    $rappel = $attendus !== [] ? $inter / count($attendus) : 1.0;
    $sommePrecision += $precision;
    $sommeRappel += $rappel;
    $maxMs = max($maxMs, $resultat->dureeMs);
    $mesuresRequetes[] = ['id' => (string) $requete['id'], 'q' => (string) ($requete['q'] ?? ''),
        'filtres' => (array) ($requete['filtres'] ?? []), 'mode' => $resultat->mode,
        'attendus' => count($attendus), 'obtenus' => count($obtenus), 'intersection' => $inter,
        'precision' => round($precision, 4), 'rappel' => round($rappel, 4),
        'duree_ms' => round($resultat->dureeMs, 1)];
}
$nbRequetes = max(1, count($requetes));
$precisionMoyenne = $sommePrecision / $nbRequetes;
$rappelMoyen = $sommeRappel / $nbRequetes;

$nbDocuments = $classement['documents'];
$tauxType = $nbDocuments > 0 ? $classement['types_ok'] / $nbDocuments : 0.0;
$tauxEmetteursVides = $nbDocuments > 0 ? $classement['emetteurs_vides'] / $nbDocuments : 1.0;

$classementOk = $nbDocuments === count($documents)
    && $tauxType >= SEUIL_TYPE_CORRECT
    && $tauxEmetteursVides <= SEUIL_EMETTEURS_VIDES
    && $classement['valides_faux'] === 0;
$rechercheOk = $erreursRequetes === []
    && $precisionMoyenne >= SEUIL_PRECISION
    && $rappelMoyen >= SEUIL_RAPPEL
    && $maxMs < BUDGET_MS_PAR_REQUETE;
$ingestionOk = $erreursDepot === [] && $documentsIndexes === count($documents) && $posesIndex === count($documents);
$franchie = $classementOk && $rechercheOk && $ingestionOk;

$res = [
    'lot' => 'ged-sim-corpus-1', 'date' => date('c'), 'seed' => (int) $verite['seed'],
    'documents' => count($documents), 'pages' => array_sum(array_map(static fn (array $d): int => (int) $d['pages'], $documents)),
    'gros_pdf' => count(array_filter($documents, static fn (array $d): bool => (int) $d['pages'] >= 50)),
    'corpus' => 'var/sim/corpus', 'verite' => 'recette/sim/verite-corpus.json',
    'duree_ingestion_s' => $dureeIngestion, 'documents_indexes' => $documentsIndexes, 'poses_index' => $posesIndex,
    'erreurs_depot' => $erreursDepot,
    'classement' => $classement + ['taux_type' => round($tauxType, 4), 'taux_emetteurs_vides' => round($tauxEmetteursVides, 4),
        'duree_s' => $dureeClassement, 'anomalies' => $anomalies],
    'recherche' => ['requetes' => count($requetes), 'precision_moyenne' => round($precisionMoyenne, 4),
        'rappel_moyen' => round($rappelMoyen, 4), 'temps_max_ms' => round($maxMs, 1),
        'erreurs' => $erreursRequetes, 'detail' => $mesuresRequetes],
    'seuils' => ['type_correct' => SEUIL_TYPE_CORRECT, 'emetteurs_vides_max' => SEUIL_EMETTEURS_VIDES,
        'precision' => SEUIL_PRECISION, 'rappel' => SEUIL_RAPPEL, 'budget_ms' => BUDGET_MS_PAR_REQUETE],
    'porte' => $franchie ? 'FRANCHIE' : 'NON FRANCHIE',
];
$preuves = "$racine/recette/preuves/sim";
is_dir($preuves) || mkdir($preuves, 0775, true);
file_put_contents("$preuves/corpus.json", json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$md = [
    '# Espace de simulation — classement et recherche — ' . date('Y-m-d H:i'),
    '',
    sprintf('Porte : **%s** — corpus synthétique régénérable (graine %d), fonds déposé par `Ged\\Socle\\DepotFichiers`,',
        $res['porte'], $res['seed']),
    'classé par `Ged\\Classement\\ClasseurGed` (règles, sans IA) et interrogé par `Ged\\Recherche\\Service`.',
    '',
    '| Mesure | Attendu | Obtenu |', '|---|---|---|',
    '| Documents | 150–300 | ' . count($documents) . ' |',
    '| Pages | — | ' . $res['pages'] . ' |',
    '| Gros PDF (≥ 50 pages) | ≥ 4 | ' . $res['gros_pdf'] . ' |',
    '| Documents indexés | ' . count($documents) . ' | ' . $documentsIndexes . ' |',
    '| Durée d’ingestion | — | ' . $dureeIngestion . ' s |',
    '',
    '## Classement automatique (règles seules)',
    '',
    '| Mesure | Seuil | Obtenu |', '|---|---|---|',
    '| Types justes | ≥ ' . (SEUIL_TYPE_CORRECT * 100) . ' % | ' . $classement['types_ok'] . '/' . $nbDocuments
        . ' (' . round($tauxType * 100, 1) . ' %) |',
    '| Émetteurs justes | — | ' . $classement['emetteurs_ok'] . '/' . $nbDocuments . ' |',
    '| Émetteurs vides | ≤ ' . (SEUIL_EMETTEURS_VIDES * 100) . ' % | ' . $classement['emetteurs_vides']
        . ' (' . round($tauxEmetteursVides * 100, 1) . ' %) |',
    '| Dates justes | — | ' . $classement['dates_ok'] . '/' . $classement['dates_attendues'] . ' |',
    '| Validés seuls | — | ' . $classement['valides'] . ' |',
    '| Validés faux | 0 | ' . $classement['valides_faux'] . ' |',
    '| Durée | — | ' . $dureeClassement . ' s |',
    '',
    '## Recherche',
    '',
    '| Mesure | Seuil | Obtenu |', '|---|---|---|',
    '| Précision moyenne | ≥ ' . (SEUIL_PRECISION * 100) . ' % | ' . round($precisionMoyenne * 100, 1) . ' % |',
    '| Rappel moyen | ≥ ' . (SEUIL_RAPPEL * 100) . ' % | ' . round($rappelMoyen * 100, 1) . ' % |',
    '| Temps max par requête | < ' . BUDGET_MS_PAR_REQUETE . ' ms | ' . round($maxMs, 1) . ' ms |',
    '',
    '| Requête | Mode | Attendus | Obtenus | Inter | Précision | Rappel | ms |',
    '|---|---|---|---|---|---|---|---|',
];
foreach ($mesuresRequetes as $m) {
    $md[] = sprintf('| `%s` | %s | %d | %d | %d | %.2f | %.2f | %.0f |', $m['id'], $m['mode'], $m['attendus'],
        $m['obtenus'], $m['intersection'], $m['precision'], $m['rappel'], $m['duree_ms']);
}
if ($anomalies !== []) {
    $md[] = '';
    $md[] = '## Anomalies de classement';
    $md[] = '';
    foreach (array_slice($anomalies, 0, 40) as $a) {
        $md[] = sprintf('- `%s` : type `%s` (attendu `%s`), émetteur `%s` (attendu `%s`), date `%s` (attendue `%s`)',
            $a['id'], $a['type_propose'] ?? '∅', $a['type_attendu'], $a['emetteur_propose'] ?? '∅',
            $a['emetteur_attendu'], $a['date_proposee'] ?? '∅', $a['date_attendue'] ?? '∅');
    }
}
file_put_contents("$preuves/corpus.md", implode("\n", $md) . "\n");

printf("sim-corpus : %s — %d documents, %d pages, types %d/%d (%.1f %%), émetteurs vides %d (%.1f %%), "
    . "dates %d/%d, validés faux %d ; recherche P %.1f %% / R %.1f %% (max %.0f ms) ; porte %s\n",
    $dureeIngestion . ' s', count($documents), $res['pages'], $classement['types_ok'], $nbDocuments,
    $tauxType * 100, $classement['emetteurs_vides'], $tauxEmetteursVides * 100, $classement['dates_ok'],
    $classement['dates_attendues'], $classement['valides_faux'], $precisionMoyenne * 100, $rappelMoyen * 100, $maxMs, $res['porte']);
exit($franchie ? 0 : 1);


/** Génère le corpus s'il manque (var/sim/, hors dépôt) ; nomme l'absence de moteur. */
function assurerCorpus(string $racine, Config $config): void
{
    $corpus = "$racine/var/sim/corpus";
    if (is_file("$racine/var/sim/lectures.json") && is_file("$racine/var/sim/verite-corpus.json")
        && is_dir($corpus) && glob("$corpus/*") !== []) {
        return;
    }
    $python = $config->get('CMD4_PYTHON');
    if ($python === '' || !is_file($python)) {
        fwrite(STDERR, "corpus absent et CMD4_PYTHON introuvable : lancez `$python tools/sim/generer-corpus.py` "
            . "(PyMuPDF requis)\n");
        exit(2);
    }
    $proc = proc_open([$python, '-X', 'utf8', "$racine/tools/sim/generer-corpus.py"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, $racine);
    if (!is_resource($proc)) {
        fwrite(STDERR, "générateur du corpus non lancé ($python)\n");
        exit(2);
    }
    $sortie = (string) stream_get_contents($tubes[1]);
    $erreurs = (string) stream_get_contents($tubes[2]);
    fclose($tubes[1]);
    fclose($tubes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || !is_file("$racine/var/sim/verite-corpus.json")) {
        fwrite(STDERR, "génération du corpus en échec (code $code) : " . mb_substr(trim($erreurs !== '' ? $erreurs : $sortie), -400) . "\n");
        exit(2);
    }
}

/** La vérité régénérée doit être identique à celle du dépôt : sinon on ne mesure pas une vérité figée. */
function veriteSynchronisee(string $racine): void
{
    $commit = "$racine/recette/sim/verite-corpus.json";
    $regenere = "$racine/var/sim/verite-corpus.json";
    if (!is_file($commit)) {
        fwrite(STDERR, "vérité versionnée absente : $commit\n");
        exit(2);
    }
    if (hash_file('sha256', $commit) !== hash_file('sha256', $regenere)) {
        fwrite(STDERR, "vérité désynchronisée : var/sim/verite-corpus.json diffère de recette/sim/verite-corpus.json "
            . "(générateur modifié ?) — regénérer puis recopier la vérité.\n");
        exit(2);
    }
}

/** @return array<string, mixed> */
function chargerJson(string $fichier): array
{
    $donnees = json_decode((string) file_get_contents($fichier), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($donnees)) {
        throw new RuntimeException("JSON illisible : $fichier");
    }

    return $donnees;
}

/** Repart d'une base de test vierge pour les tables de la GED (jamais une table d'un autre secteur en écriture). */
function reinitialiser(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['ingestion_travaux', 'ingestion_messages', 'document_versions', 'document_deplacements',
        'documents', 'classement_choix', 'recherche_textes'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS $table");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    SchemaSocle::installer($pdo);
    SchemaClassement::installer($pdo);
    SchemaRecherche::installer($pdo);
    if (class_exists(\Ged\Securite\Schema::class)) {
        \Ged\Securite\Schema::installer($pdo);   // dossiers de droits : le dépôt les rattache
    }
}

/** Vide un dossier régénérable sans supprimer le dossier racine (fonds de simulation). */
function viderDossier(string $chemin): void
{
    if (!is_dir($chemin)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $element) {
        if ($element instanceof SplFileInfo) {
            $element->isDir() ? @rmdir($element->getPathname()) : @unlink($element->getPathname());
        }
    }
}
