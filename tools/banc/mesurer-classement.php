<?php

declare(strict_types=1);

/**
 * Porte V2-03 : mesure le classement d'un lot du corpus contre sa vérité, À TRAVERS le classeur de la GED
 * (Ged\Classement\ClasseurGed), c'est-à-dire exactement ce que la tâche d'ingestion exécute.
 *
 *   php tools/banc/mesurer-classement.php [courrier-100p|classement-bruite] [--ia|--ia-simule]
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
use Ged\Classement\ClientIa;
use Ged\Classement\ClientIaNul;
use Ged\Classement\Habitudes;
use Ged\Classement\InfomaniakClient;
use Ged\Classement\ReponseIa;
use Ged\Classement\Regles;
use Ged\Classement\Schema;
use Ged\Config;

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

// Le banc est un test : il ne touche jamais la base de dev.
putenv('DB_NAME=gedv2_test');

$lot = 'courrier-100p';
$avecIa = false;
$iaSimule = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--ia') {
        $avecIa = true;
    } elseif ($a === '--ia-simule') {
        $iaSimule = true;
    } else {
        $lot = $a;
    }
}

$reponsesSimulees = [];
if ($lot === 'classement-bruite') {
    // Porte R2 autonome : OCR synthétique, réponses IA figées dans la vérité, aucun réseau ni document réel.
    $corpus = json_decode((string) file_get_contents("$racine/tests/corpus/classement-bruite/verite.json"), true, 512, JSON_THROW_ON_ERROR);
    $pieces = [];
    $elements = [];
    foreach ($corpus['pieces'] as $i => $piece) {
        $page = $i + 1;
        $pieces[] = ['pages' => [$page], 'texte' => $piece['texte'], 'analyse' => [], 'fichier' => $piece['id'] . '.pdf'];
        $elements[] = ['pages' => [$page], 'type' => $piece['type'], 'correspondant' => $piece['emetteur'],
            'date' => $piece['date'] !== null ? date('d.m.Y', strtotime($piece['date'])) : null];
        $ia = $piece['ia'];
        $reponsesSimulees[$piece['id']] = new ReponseIa($ia['type'], $ia['emetteur'], null, $ia['date'], null, [],
            (float) $ia['confiance'], 'simule-bruite');
    }
    $verite = ['elements' => $elements];
    $oracle = ['lot' => $lot, 'seuils' => ['valides_type_faux' => 0, 'valides_faux' => 0,
        'emetteurs_faux' => 0, 'emetteurs_faux_valides' => 0, 'emetteurs_vides_max' => 0.15,
        'autre_valides_seuls' => 0, 'dates_fausses' => 0, 'dates_vides_factures_max' => 0.15,
        'titres_invalides' => 0, 'taux_valide_seul_min' => 0.0],
        'attendu' => ['documents' => count($pieces), 'valides_seuls_sans_ia' => 0, 'valides_seuls_avec_ia_min' => 3]];
    if ($avecIa && !$iaSimule) {
        fwrite(STDERR, "Le corpus classement-bruite est déterministe : utilisez --ia-simule, jamais une IA réelle.\n");
        exit(2);
    }
} else {
    $oracle = json_decode((string) file_get_contents("$racine/tests/oracles/classement/$lot.json"), true, 512, JSON_THROW_ON_ERROR);
    $fPieces = $racine . '/' . $oracle['pieces'];
    if (!is_file($fPieces)) {
        fwrite(STDERR, "Pièces absentes : lancez d'abord `php tools/banc/mesurer-decoupe.php $lot`\n");
        exit(2);
    }
    $pieces = json_decode((string) file_get_contents($fPieces), true, 512, JSON_THROW_ON_ERROR);
    $verite = json_decode((string) file_get_contents($racine . '/' . $oracle['verite']), true, 512, JSON_THROW_ON_ERROR);
}
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
$client = $avecIa ? new InfomaniakClient($config) : new ClientIaNul();
if ($iaSimule) {
    $client = new class($reponsesSimulees) implements ClientIa {
        /** @param array<string, ReponseIa> $reponses */
        public function __construct(private readonly array $reponses) {}
        public function classer(string $texte, string $nomFichier, array $typesConnus): ?ReponseIa
        {
            return $this->reponses[pathinfo($nomFichier, PATHINFO_FILENAME)] ?? null;
        }
        public function aExpire(): bool { return false; }
        public function disponible(): bool { return true; }
        public function modele(): string { return 'simule-bruite'; }
    };
}
$classeur = new ClasseurGed($config, $pdo, $client, new Habitudes($pdo));
$mode = $iaSimule ? 'avec-ia-simule' : ($avecIa ? 'avec-ia' : 'sans-ia');

$t0 = microtime(true);
$mes = ['documents' => 0, 'valides' => 0, 'valides_type_faux' => 0, 'valides_faux' => 0, 'types_faux' => 0,
    'emetteurs_lus' => 0, 'emetteurs_vides' => 0, 'emetteurs_faux' => 0, 'emetteurs_faux_valides' => 0,
    'factures' => 0, 'dates_vides_factures' => 0, 'autre_valides_seuls' => 0,
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
    $mes['valides_faux'] += (int) ($r['valide'] && ($typeFaux || $emetteurFaux || $dateFausse));
    $mes['autre_valides_seuls'] += (int) ($r['valide'] && Regles::estAutre($r['type']));
    $mes['emetteurs_lus'] += (int) $emetteurLu;
    $mes['emetteurs_vides'] += (int) !$emetteurLu;
    $mes['emetteurs_faux'] += (int) $emetteurFaux;
    $mes['emetteurs_faux_valides'] += (int) ($r['valide'] && $emetteurFaux);
    $mes['factures'] += (int) Regles::memeType($el['type'] ?? null, 'Facture');
    $mes['dates_vides_factures'] += (int) (Regles::memeType($el['type'] ?? null, 'Facture') && ($r['date'] ?? null) === null);
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
$mesureIa = $client instanceof InfomaniakClient ? $client->statistiques() : ['appels' => 0, 'temps_s' => 0.0];
$tempsParPiece = $mes['documents'] > 0 ? round($mesureIa['temps_s'] / $mes['documents'], 3) : 0.0;
$taux = $mes['documents'] > 0 ? round($mes['valides'] / $mes['documents'], 4) : 0.0;
$tauxEmetteursVides = $mes['documents'] > 0 ? round($mes['emetteurs_vides'] / $mes['documents'], 4) : 0.0;
$tauxDatesVidesFactures = $mes['factures'] > 0 ? round($mes['dates_vides_factures'] / $mes['factures'], 4) : 0.0;
$attendu = $oracle['attendu'];
$modeAvecIa = $avecIa || $iaSimule;
$validesAttendus = $modeAvecIa ? (int) $attendu['valides_seuls_avec_ia_min'] : (int) $attendu['valides_seuls_sans_ia'];
$franchie = $mes['documents'] === (int) $attendu['documents']
    && $mes['valides_type_faux'] <= (int) $seuils['valides_type_faux']
    && $mes['valides_faux'] <= (int) ($seuils['valides_faux'] ?? 0)
    && $mes['emetteurs_faux'] <= (int) $seuils['emetteurs_faux']
    && $mes['emetteurs_faux_valides'] <= (int) ($seuils['emetteurs_faux_valides'] ?? 0)
    && $tauxEmetteursVides <= (float) ($seuils['emetteurs_vides_max'] ?? 1.0)
    && $mes['autre_valides_seuls'] <= (int) $seuils['autre_valides_seuls']
    && $mes['dates_fausses'] <= (int) $seuils['dates_fausses']
    && $tauxDatesVidesFactures <= (float) ($seuils['dates_vides_factures_max'] ?? 1.0)
    && $mes['titres_invalides'] <= (int) $seuils['titres_invalides']
    && ($modeAvecIa
        ? ($taux >= (float) $seuils['taux_valide_seul_min'] && $mes['valides'] >= $validesAttendus)
        : ($mes['valides'] <= $validesAttendus));

$res = [
    'lot' => $lot, 'mode' => $mode, 'date' => date('c'), 'duree_s' => $duree,
    'ia_appels' => $mesureIa['appels'], 'ia_temps_s' => $mesureIa['temps_s'], 'ia_temps_par_piece_s' => $tempsParPiece,
    'chemin' => 'Ged\\Classement\\ClasseurGed', 'seuil' => (float) $config->get('CLASSEMENT_SEUIL', '0.8'),
    'documents' => $mes['documents'], 'valides' => $mes['valides'], 'taux_valide_seul' => $taux,
    'valides_attendus' => $validesAttendus,
    'valides_type_faux' => $mes['valides_type_faux'], 'valides_faux' => $mes['valides_faux'], 'types_faux' => $mes['types_faux'],
    'emetteurs_lus' => $mes['emetteurs_lus'], 'emetteurs_vides' => $mes['emetteurs_vides'],
    'taux_emetteurs_vides' => $tauxEmetteursVides, 'emetteurs_faux' => $mes['emetteurs_faux'],
    'emetteurs_faux_valides' => $mes['emetteurs_faux_valides'],
    'factures' => $mes['factures'], 'dates_vides_factures' => $mes['dates_vides_factures'],
    'taux_dates_vides_factures' => $tauxDatesVidesFactures,
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
    '| Validés seuls faux (type, émetteur ou date) | 0 | ' . $mes['valides_faux'] . ' |',
    '| Types faux (validés ou non) | 0 | ' . $mes['types_faux'] . ' |',
    '| Émetteurs lus | — | ' . $mes['emetteurs_lus'] . ' |',
    '| Émetteurs vides | ≤ ' . round(($seuils['emetteurs_vides_max'] ?? 1) * 100, 1) . ' % | ' . $mes['emetteurs_vides'] . ' (' . round($tauxEmetteursVides * 100, 1) . ' %) |',
    '| Émetteurs faux | 0 | ' . $mes['emetteurs_faux'] . ' |',
    '| Émetteurs faux parmi validés | 0 | ' . $mes['emetteurs_faux_valides'] . ' |',
    '| « Autre » validé seul | 0 | ' . $mes['autre_valides_seuls'] . ' |',
    '| Dates fausses (en-tête) | 0 | ' . $mes['dates_fausses'] . ' |',
    '| Dates vides des factures | ≤ ' . round(($seuils['dates_vides_factures_max'] ?? 1) * 100, 1) . ' % | '
        . $mes['dates_vides_factures'] . '/' . $mes['factures'] . ' (' . round($tauxDatesVidesFactures * 100, 1) . ' %) |',
    '| Titres invalides (vide ou nom du lot) | 0 | ' . $mes['titres_invalides'] . ' |',
    '| Appels IA | — | ' . $mesureIa['appels'] . ' |',
    '| Temps IA / pièce | — | ' . $tempsParPiece . ' s |',
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

printf("%s : %s — %d documents, %d validés seuls (%.1f %%), validés faux %d, types faux %d, émetteurs vides %d (%.1f %%), émetteurs faux %d, dates vides factures %d/%d (%.1f %%), dates fausses %d, titres invalides %d, « Autre » validé %d ; porte %s\n",
    $mode, $duree . ' s', $mes['documents'], $mes['valides'], $taux * 100,
    $mes['valides_faux'], $mes['types_faux'], $mes['emetteurs_vides'], $tauxEmetteursVides * 100, $mes['emetteurs_faux'],
    $mes['dates_vides_factures'], $mes['factures'], $tauxDatesVidesFactures * 100, $mes['dates_fausses'],
    $mes['titres_invalides'], $mes['autre_valides_seuls'], $res['porte']);
exit($franchie ? 0 : 1);
