<?php

declare(strict_types=1);

/**
 * Porte V2-03 : mesure le classement d'un lot du corpus contre sa vérité, À TRAVERS le classeur de la GED
 * (Ged\Classement\ClasseurGed), c'est-à-dire exactement ce que la tâche d'ingestion exécute.
 *
 *   php tools/banc/mesurer-classement.php [courrier-100p] [--ia]
 *
 * Par défaut, aucune IA n'est appelée (client inerte) : on mesure le socle déterministe règles + habitudes.
 * --ia branche Infomaniak (réseau) et confirme/porte la source des types corroborés.
 *
 * Mesure : validés seuls avec un type faux (0), émetteurs faux (0 ; non lu = vide), « Autre » validé seul (0),
 * date de l'en-tête exacte (0 fausse), titre issu de la pièce (0 vide et 0 « nom du lot »), taux validé seul.
 * Règle V2-03 : valider seul = habitude OU (IA ≥ seuil ET indice du même type dans le texte).
 * Sans IA (défaut), la porte exige donc 0 validé seul (hors habitudes) ; avec --ia, elle exige le taux minimum.
 * Sortie : recette/preuves/classement/<lot>-classement-<mode>.{json,md} ; code 0 si la porte est franchie.
 */

use Ged\Base;
use Ged\Classement\ClasseurGed;
use Ged\Classement\ClientIaNul;
use Ged\Classement\Habitudes;
use Ged\Classement\InfomaniakClient;
use Ged\Classement\Regles;
use Ged\Classement\Schema;
use Ged\Config;

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

// Le banc est un test : il ne touche jamais la base de dev.
putenv('DB_NAME=gedv2_test');

$lot = 'courrier-100p';
$avecIa = false;
foreach (array_slice($argv, 1) as $a) {
    $a === '--ia' ? $avecIa = true : $lot = $a;
}

$oracle = json_decode((string) file_get_contents("$racine/tests/oracles/classement/$lot.json"), true, 512, JSON_THROW_ON_ERROR);
$fPieces = $racine . '/' . $oracle['pieces'];
if (!is_file($fPieces)) {
    fwrite(STDERR, "Pièces absentes : lancez d'abord `php tools/banc/mesurer-decoupe.php $lot`\n");
    exit(2);
}
$pieces = json_decode((string) file_get_contents($fPieces), true, 512, JSON_THROW_ON_ERROR);
$verite = json_decode((string) file_get_contents($racine . '/' . $oracle['verite']), true, 512, JSON_THROW_ON_ERROR);
$seuils = $oracle['seuils'];

$parPage = [];
foreach ($verite['elements'] as $el) {
    foreach ($el['pages'] as $page) {
        $parPage[$page] = $el;
    }
}

$config = new Config($racine);
$pdo = Base::pdo($config);
// Base de TEST : on repart d'une mémoire d'apprentissage vide pour que la mesure soit reproductible.
$pdo->exec('DROP TABLE IF EXISTS classement_choix');
Schema::installer($pdo);
$classeur = new ClasseurGed($config, $pdo, $avecIa ? new InfomaniakClient($config) : new ClientIaNul(), new Habitudes($pdo));
$mode = $avecIa ? 'avec-ia' : 'sans-ia';

$t0 = microtime(true);
$mes = ['documents' => 0, 'valides' => 0, 'valides_type_faux' => 0, 'types_faux' => 0,
    'emetteurs_lus' => 0, 'emetteurs_faux' => 0, 'autre_valides_seuls' => 0,
    'dates_fausses' => 0, 'titres_invalides' => 0];
$detail = [];
foreach ($pieces as $piece) {
    $pages = $piece['pages'];
    $el = $parPage[$pages[0]] ?? null;
    if ($el === null || !empty($el['blanche'])) {
        continue;
    }
    $mes['documents']++;
    $r = $classeur->proposer((string) $piece['texte'], (array) ($piece['analyse'] ?? []), basename((string) $piece['fichier']));
    $typeFaux = !Regles::memeType($r['type'], $el['type'] ?? null);
    $emetteurLu = ($r['emetteur'] ?? null) !== null && $r['emetteur'] !== '';
    $emetteurFaux = $emetteurLu && Regles::normaliser((string) $r['emetteur']) !== Regles::normaliser((string) ($el['correspondant'] ?? ''));
    // Vérité au format suisse JJ.MM.AAAA → ISO ; le titre ne doit jamais être le nom du lot (fichier source).
    $dateAttendue = preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', (string) ($el['date'] ?? ''), $md) === 1
        ? sprintf('%04d-%02d-%02d', (int) $md[3], (int) $md[2], (int) $md[1]) : null;
    $dateFausse = $dateAttendue !== null && ($r['date'] ?? null) !== $dateAttendue;
    $titre = trim((string) ($r['titre'] ?? ''));
    $stemLot = (string) preg_replace('/[_\-]?part\d+$/i', '', pathinfo((string) $piece['fichier'], PATHINFO_FILENAME));
    $titreInvalide = $titre === '' || ($stemLot !== '' && str_contains(Regles::normaliser($titre), Regles::normaliser($stemLot)));
    $mes['types_faux'] += (int) $typeFaux;
    $mes['valides'] += (int) $r['valide'];
    $mes['valides_type_faux'] += (int) ($r['valide'] && $typeFaux);
    $mes['autre_valides_seuls'] += (int) ($r['valide'] && Regles::estAutre($r['type']));
    $mes['emetteurs_lus'] += (int) $emetteurLu;
    $mes['emetteurs_faux'] += (int) $emetteurFaux;
    $mes['dates_fausses'] += (int) $dateFausse;
    $mes['titres_invalides'] += (int) $titreInvalide;
    $detail[] = [
        'pages' => $pages, 'type_attendu' => $el['type'] ?? null, 'type_propose' => $r['type'],
        'emetteur_attendu' => $el['correspondant'] ?? null, 'emetteur_propose' => $r['emetteur'],
        'date_attendue' => $dateAttendue, 'date_proposee' => $r['date'], 'titre_propose' => $r['titre'],
        'source' => $r['source'], 'preuve' => $r['preuve'], 'confiance' => $r['confiance'],
        'valide' => $r['valide'], 'raison' => $r['raison'],
    ];
}
$duree = round(microtime(true) - $t0, 2);
$taux = $mes['documents'] > 0 ? round($mes['valides'] / $mes['documents'], 4) : 0.0;
$attendu = $oracle['attendu'];
$validesAttendus = $avecIa ? (int) $attendu['valides_seuls_avec_ia_min'] : (int) $attendu['valides_seuls_sans_ia'];
$franchie = $mes['documents'] === (int) $attendu['documents']
    && $mes['valides_type_faux'] <= (int) $seuils['valides_type_faux']
    && $mes['emetteurs_faux'] <= (int) $seuils['emetteurs_faux']
    && $mes['autre_valides_seuls'] <= (int) $seuils['autre_valides_seuls']
    && $mes['dates_fausses'] <= (int) $seuils['dates_fausses']
    && $mes['titres_invalides'] <= (int) $seuils['titres_invalides']
    && ($avecIa
        ? ($taux >= (float) $seuils['taux_valide_seul_min'] && $mes['valides'] >= $validesAttendus)
        : ($mes['valides'] <= $validesAttendus));

$res = [
    'lot' => $lot, 'mode' => $mode, 'date' => date('c'), 'duree_s' => $duree,
    'chemin' => 'Ged\\Classement\\ClasseurGed', 'seuil' => (float) $config->get('CLASSEMENT_SEUIL', '0.8'),
    'documents' => $mes['documents'], 'valides' => $mes['valides'], 'taux_valide_seul' => $taux,
    'valides_attendus' => $validesAttendus,
    'valides_type_faux' => $mes['valides_type_faux'], 'types_faux' => $mes['types_faux'],
    'emetteurs_lus' => $mes['emetteurs_lus'], 'emetteurs_faux' => $mes['emetteurs_faux'],
    'autre_valides_seuls' => $mes['autre_valides_seuls'],
    'dates_fausses' => $mes['dates_fausses'], 'titres_invalides' => $mes['titres_invalides'],
    'seuils' => $seuils, 'porte' => $franchie ? 'FRANCHIE' : 'NON FRANCHIE', 'detail' => $detail,
];

$preuves = $racine . '/recette/preuves/classement';
is_dir($preuves) || mkdir($preuves, 0775, true);
file_put_contents("$preuves/$lot-classement-$mode.json", json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$md = [
    "# Classement — lot `$lot` — $mode — " . date('Y-m-d H:i'),
    '',
    "Porte V2-03 : **{$res['porte']}** (via `Ged\\Classement\\ClasseurGed`, seuil {$res['seuil']})",
    '',
    '| Mesure | Attendu | Obtenu |', '|---|---|---|',
    '| Documents | ' . $oracle['attendu']['documents'] . ' | ' . $mes['documents'] . ' |',
    '| Validés seuls | — | ' . $mes['valides'] . ' (' . round($taux * 100, 1) . ' %) |',
    '| Validés seuls attendus (' . $mode . ') | ' . $validesAttendus . ' | ' . $mes['valides'] . ' |',
    '| Validés seuls, type faux | 0 | ' . $mes['valides_type_faux'] . ' |',
    '| Types faux (validés ou non) | 0 | ' . $mes['types_faux'] . ' |',
    '| Émetteurs lus | — | ' . $mes['emetteurs_lus'] . ' |',
    '| Émetteurs faux | 0 | ' . $mes['emetteurs_faux'] . ' |',
    '| « Autre » validé seul | 0 | ' . $mes['autre_valides_seuls'] . ' |',
    '| Dates fausses (en-tête) | 0 | ' . $mes['dates_fausses'] . ' |',
    '| Titres invalides (vide ou nom du lot) | 0 | ' . $mes['titres_invalides'] . ' |',
    '| Durée | — | ' . $duree . ' s |',
    '',
];
$anomalies = array_values(array_filter($detail, static fn (array $d): bool => $d['type_propose'] !== $d['type_attendu']
    || ($d['emetteur_propose'] !== null && $d['emetteur_propose'] !== $d['emetteur_attendu'])
    || ($d['date_attendue'] !== null && $d['date_proposee'] !== $d['date_attendue'])
    || trim((string) $d['titre_propose']) === ''));
if ($anomalies !== []) {
    $md[] = '## Anomalies';
    foreach ($anomalies as $a) {
        $md[] = '- pages ' . implode(',', $a['pages']) . ' : type `' . ($a['type_propose'] ?? '∅') . '` (attendu `' . $a['type_attendu']
            . '`), émetteur `' . ($a['emetteur_propose'] ?? '∅') . '` (attendu `' . $a['emetteur_attendu']
            . '`), date `' . ($a['date_proposee'] ?? '∅') . '` (attendu `' . ($a['date_attendue'] ?? '∅')
            . '`), titre `' . ($a['titre_propose'] ?? '∅') . '`';
    }
    $md[] = '';
}
$aControler = array_values(array_filter($detail, static fn (array $d): bool => !$d['valide']));
if ($aControler !== []) {
    $md[] = '## Laissés à contrôler';
    foreach ($aControler as $a) {
        $md[] = '- pages ' . implode(',', $a['pages']) . ' : `' . ($a['type_propose'] ?? '∅') . '` proposé — ' . ($a['raison'] ?? '');
    }
    $md[] = '';
}
file_put_contents("$preuves/$lot-classement-$mode.md", implode("\n", $md) . "\n");

printf("%s : %s — %d documents, %d validés seuls (%.1f %%), types faux %d, émetteurs faux %d, dates fausses %d, titres invalides %d, « Autre » validé %d ; porte %s\n",
    $mode, $duree . ' s', $mes['documents'], $mes['valides'], $taux * 100, $mes['types_faux'], $mes['emetteurs_faux'],
    $mes['dates_fausses'], $mes['titres_invalides'], $mes['autre_valides_seuls'], $res['porte']);
exit($franchie ? 0 : 1);
