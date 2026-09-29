<?php

declare(strict_types=1);

/**
 * Porte V2-02 : mesure la découpe d'un lot du corpus contre sa vérité, À TRAVERS le découpeur de la GED
 * (Ged\Ingestion\Cmd4Decoupeur), c'est-à-dire exactement ce que la tâche d'ingestion exécute.
 *
 *   php tools/banc/mesurer-decoupe.php [courrier-100p] [--vision]
 *
 * Pages blanches NEUTRES : rattachées à un document ou retirées, peu importe, tant qu'elles ne deviennent pas
 * un document à elles seules. Sortie : recette/preuves/ingestion/<lot>-decoupe.json + .md ;
 * code 0 si la porte est franchie (tous exacts, 0 page perdue, 0 doublon, aucune blanche devenue document).
 */

use Ged\Config;
use Ged\Ingestion\Cmd4Decoupeur;

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

$lot = 'courrier-100p';
$vision = false;
foreach (array_slice($argv, 1) as $a) {
    $a === '--vision' ? $vision = true : $lot = $a;
}
$config = new Config($racine);
$dossierLot = $racine . '/tests/corpus/' . $lot;
$verite = json_decode((string) file_get_contents($dossierLot . '/verite.json'), true, 512, JSON_THROW_ON_ERROR);

$sortie = $racine . '/var/banc/' . $lot . '-' . date('Ymd-His');
$decoupeur = new Cmd4Decoupeur($config->get('CMD4_PYTHON', 'python'), $config->get('CMD4_PRODUIT'), 1800, $vision);
$t0 = microtime(true);
$pieces = $decoupeur->decouper($dossierLot . '/' . $verite['fichier'], $sortie);
$duree = round(microtime(true) - $t0, 1);
$groupes = array_map(fn ($p) => $p['pages'], $pieces);
// Pièces gardées pour les bancs suivants (classement) : pas besoin de redécouper pour mesurer le classement.
file_put_contents($racine . '/var/banc/' . $lot . '-pieces.json', json_encode($pieces, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// Vérité : page → élément ; blanches à part.
$proprio = [];
$blanches = [];
$docs = [];
foreach ($verite['elements'] as $i => $el) {
    foreach ($el['pages'] as $pg) {
        $proprio[$pg] = !empty($el['blanche']) ? "B$i" : $i;
    }
    !empty($el['blanche']) ? $blanches[] = $el['pages'][0] : $docs[$i] = $el;
}
$sortieDe = [];
$doublons = [];
foreach ($groupes as $k => $g) {
    foreach ($g as $pg) {
        if (isset($sortieDe[$pg])) {
            $doublons[] = $pg;
        }
        $sortieDe[$pg] = $k;
    }
}
$n = (int) $verite['pages'];
$perdues = array_values(array_filter(range(1, $n), fn ($p) => !isset($sortieDe[$p]) && !in_array($p, $blanches, true)));
$utiles = array_values(array_filter(range(1, $n), fn ($p) => !in_array($p, $blanches, true)));
$vraies = $trouvees = 0;
$manquees = $fausses = [];
for ($i = 0; $i < count($utiles) - 1; $i++) {
    [$p, $q] = [$utiles[$i], $utiles[$i + 1]];
    $v = $proprio[$p] !== $proprio[$q];
    $c = ($sortieDe[$p] ?? -1) !== ($sortieDe[$q] ?? -2);
    if ($v) {
        $vraies++;
        $c ? $trouvees++ : $manquees[] = $p;
    } elseif ($c) {
        $fausses[] = $p;
    }
}
$lignes = [];
$exactes = 0;
foreach ($docs as $el) {
    $couvrant = array_values(array_unique(array_map(fn ($pg) => $sortieDe[$pg] ?? null, $el['pages']), SORT_REGULAR));
    $exact = count($couvrant) === 1 && $couvrant[0] !== null
        && array_values(array_diff($groupes[$couvrant[0]], $blanches)) == $el['pages'];
    $exactes += (int) $exact;
    $lignes[] = ['n' => $el['n'], 'pages' => $el['pages'], 'type' => $el['type'], 'scan' => $el['scan'], 'cas' => $el['cas'] ?? null,
        'decoupe' => $exact ? 'exacte' : (count($couvrant) > 1 ? 'trop coupé (' . count($couvrant) . ' morceaux)' : 'fusionné avec un autre')];
}
$devenir = [];
foreach ($blanches as $pg) {
    $k = $sortieDe[$pg] ?? null;
    $devenir[] = ['page' => $pg, 'devenir' => $k === null ? 'retirée' : (count($groupes[$k]) === 1 ? 'DEVENUE UN DOCUMENT' : 'rattachée')];
}
$blancheDocument = count(array_filter($devenir, fn ($d) => $d['devenir'] === 'DEVENUE UN DOCUMENT'));
$franchie = $exactes === count($docs) && $perdues === [] && $doublons === [] && $blancheDocument === 0;

$res = [
    'lot' => $lot, 'mode' => $vision ? 'avec-vision' : 'sans-vision', 'date' => date('c'), 'duree_s' => $duree,
    'chemin' => 'Ged\\Ingestion\\Cmd4Decoupeur', 'pages' => $n,
    'documents_attendus' => count($docs), 'documents_produits' => count($groupes), 'decoupe_exacte' => $exactes,
    'frontieres' => ['vraies' => $vraies, 'trouvees' => $trouvees, 'manquees' => $manquees, 'fausses_coupes' => $fausses],
    'pages_perdues' => $perdues, 'pages_en_double' => array_values(array_unique($doublons)), 'blanches' => $devenir,
    'porte' => $franchie ? 'FRANCHIE' : 'NON FRANCHIE', 'documents' => $lignes,
];
$preuves = $racine . '/recette/preuves/ingestion';
is_dir($preuves) || mkdir($preuves, 0775, true);
file_put_contents("$preuves/$lot-decoupe.json", json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$md = ["# Découpe — lot `$lot` — {$res['mode']} — " . date('Y-m-d H:i'), '', "Porte V2-02 : **{$res['porte']}** (via `Cmd4Decoupeur`, comme la tâche d'ingestion)", '',
    '| Mesure | Attendu | Obtenu |', '|---|---|---|',
    "| Durée | — | $duree s |",
    '| Documents | ' . count($docs) . ' | ' . count($groupes) . ' |',
    '| Découpe exacte | ' . count($docs) . " | $exactes |",
    "| Frontières | $vraies | $trouvees trouvées, " . count($manquees) . ' manquées, ' . count($fausses) . ' fausses coupes |',
    '| Pages perdues | 0 | ' . count($perdues) . ' |', '| Pages en double | 0 | ' . count(array_unique($doublons)) . ' |',
    "| Blanches devenues documents | 0 | $blancheDocument |", ''];
foreach ($lignes as $l) {
    if ($l['decoupe'] !== 'exacte') {
        $md[] = "- n°{$l['n']} pages " . implode(',', $l['pages']) . " ({$l['type']}) : {$l['decoupe']}";
    }
}
file_put_contents("$preuves/$lot-decoupe.md", implode("\n", $md) . "\n");
printf("%s : %s s — %d documents pour %d ; exacts %d/%d ; frontières %d/%d, %d fausses ; perdues %d ; porte %s\n",
    $res['mode'], $duree, count($groupes), count($docs), $exactes, count($docs), $trouvees, $vraies, count($fausses), count($perdues), $res['porte']);
exit($franchie ? 0 : 1);
