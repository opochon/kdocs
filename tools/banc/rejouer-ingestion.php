<?php

declare(strict_types=1);

/**
 * Flux F1 de bout en bout, sur base de TEST et fonds temporaire : un lot est posé dans le dossier d'ingestion,
 * la tâche d'ingestion tourne jusqu'à épuisement (relevé, découpe CMD v4, classement, dépôt Socle), puis on
 * vérifie ce que le DOSSIER contient — c'est lui qui fait foi.
 *
 *   php tools/banc/rejouer-ingestion.php [courrier-100p] [--avec-ia]
 *
 * Sortie : recette/preuves/ingestion/<lot>-bout-en-bout.{json,md} ; code 0 si la porte est franchie :
 * autant de documents dans le fonds que la vérité en attend, chacun avec son .meta.json (origine = lot + pages),
 * 0 page perdue, 0 travail en erreur, et le même lot redéposé n'entre pas deux fois.
 */

use Ged\Base;
use Ged\Classement\ClasseurGed;
use Ged\Classement\ClientIaNul;
use Ged\Config;
use Ged\Ingestion\Fabrique;

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

$lot = 'courrier-100p';
$avecIa = false;
foreach (array_slice($argv, 1) as $a) {
    $a === '--avec-ia' ? $avecIa = true : $lot = $a;
}

// Même verrou que PHPUnit : un seul exécutant à la fois sur gedv2_test (tests/bootstrap.php).
@mkdir("$racine/var", 0775, true);
$verrou = fopen("$racine/var/gedv2_test.lock", 'c');
flock($verrou, LOCK_EX);

// Environnement de TEST imposé : base gedv2_test, fonds et zones dans un dossier temporaire neuf.
$bac = sys_get_temp_dir() . '/gedv2-f1-' . date('Ymd-His');
foreach (['DB_NAME' => 'gedv2_test', 'APP_ENV' => 'test', 'STORAGE_ROOT' => "$bac/fonds",
    'INGESTION_DOSSIER' => "$bac/entree", 'INGESTION_TRAVAIL' => "$bac/travail", 'INGESTION_IMAP_HOTE' => ''] as $k => $v) {
    putenv("$k=$v");
}
mkdir("$bac/entree", 0775, true);
mkdir("$bac/fonds", 0775, true);
$config = new Config($racine);
Base::assurerBase($config);
$pdo = Base::pdo($config);
// Base de TEST uniquement : on repart de tables vides pour que le décompte soit celui de ce rejeu.
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['ingestion_travaux', 'ingestion_messages', 'document_versions', 'document_deplacements', 'documents', 'classement_choix'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS $t");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
foreach (['Socle', 'Ingestion', 'Classement'] as $s) {
    ("Ged\\$s\\Schema")::installer($pdo);
}

$verite = json_decode((string) file_get_contents("$racine/tests/corpus/$lot/verite.json"), true, 512, JSON_THROW_ON_ERROR);
$attendus = count(array_filter($verite['elements'], fn ($e) => empty($e['blanche'])));
copy("$racine/tests/corpus/$lot/{$verite['fichier']}", "$bac/entree/{$verite['fichier']}");

$depot = new \Ged\Socle\DepotFichiers($config);
$classeur = new ClasseurGed($config, $pdo, $avecIa ? null : new ClientIaNul());
$pilote = Fabrique::pilote($config, $pdo, $depot, $classeur);

$t0 = microtime(true);
$tours = [];
for ($i = 0; $i < 20; $i++) {
    $b = $pilote->tic(120);
    $tours[] = $b;
    $restant = (int) $pdo->query("SELECT COUNT(*) FROM ingestion_travaux WHERE etat IN ('recu','pret')")->fetchColumn();
    if ($i > 0 && $restant === 0 && $b['recus'] + $b['decoupes'] + $b['ranges'] === 0) {
        break;
    }
}
$duree = round(microtime(true) - $t0, 1);

// Redépôt du même lot : il doit être reconnu et mis de côté, pas réingéré.
copy("$racine/tests/corpus/$lot/{$verite['fichier']}", "$bac/entree/{$verite['fichier']}");
$pilote->tic(5);
$b2 = $pilote->tic(5);

// Ce que dit le DOSSIER (il fait foi).
$metas = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$bac/fonds", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (str_ends_with($f->getFilename(), '.meta.json') && !str_contains($f->getPathname(), '.versions')) {
        $metas[] = json_decode((string) file_get_contents($f->getPathname()), true) ?: [];
    }
}
$pagesRangees = [];
foreach ($metas as $m) {
    foreach (preg_split('/[,\s]+/', (string) ($m['origine']['pages'] ?? '')) ?: [] as $plage) {
        if (preg_match('/^(\d+)(?:-(\d+))?$/', $plage, $p)) {
            foreach (range((int) $p[1], (int) ($p[2] ?? $p[1])) as $n) {
                $pagesRangees[] = $n;
            }
        }
    }
}
$blanches = [];
foreach ($verite['elements'] as $e) {
    if (!empty($e['blanche'])) {
        $blanches = array_merge($blanches, $e['pages']);
    }
}
$perdues = array_values(array_diff(range(1, (int) $verite['pages']), $pagesRangees, $blanches));
$erreurs = $pdo->query("SELECT nom_origine, erreur FROM ingestion_travaux WHERE etat = 'erreur'")->fetchAll(PDO::FETCH_ASSOC);
$valides = count(array_filter($metas, fn ($m) => ($m['statut'] ?? '') === 'valide'));
$enDb = (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
$franchie = count($metas) === $attendus && $enDb === $attendus && $perdues === [] && $erreurs === [] && $b2['doublons'] + $b2['recus'] === 1 && $b2['recus'] === 0;

$res = ['lot' => $lot, 'date' => date('c'), 'ia' => $avecIa, 'duree_s' => $duree, 'documents_attendus' => $attendus,
    'meta_json_dans_le_fonds' => count($metas), 'documents_indexes' => $enDb, 'valides_seuls' => $valides,
    'pages_perdues' => $perdues, 'travaux_en_erreur' => $erreurs, 'redepot' => $b2, 'tours' => $tours,
    'porte' => $franchie ? 'FRANCHIE' : 'NON FRANCHIE', 'fonds' => "$bac/fonds"];
$preuves = "$racine/recette/preuves/ingestion";
is_dir($preuves) || mkdir($preuves, 0775, true);
file_put_contents("$preuves/$lot-bout-en-bout.json", json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents("$preuves/$lot-bout-en-bout.md", implode("\n", [
    "# F1 de bout en bout — lot `$lot` — " . ($avecIa ? 'avec IA' : 'sans IA') . ' — ' . date('Y-m-d H:i'), '',
    "Porte : **{$res['porte']}** — dossier d'ingestion → tâche → découpe → classement → dépôt Socle", '',
    '| Mesure | Attendu | Obtenu |', '|---|---|---|',
    "| Durée | < 10 min | $duree s |",
    "| `.meta.json` dans le fonds | $attendus | " . count($metas) . ' |',
    "| Documents indexés | $attendus | $enDb |",
    '| Pages perdues | 0 | ' . count($perdues) . ' |',
    '| Travaux en erreur | 0 | ' . count($erreurs) . ' |',
    '| Même lot redéposé | mis de côté | ' . ($b2['doublons'] === 1 ? 'mis de côté' : 'RÉINGÉRÉ') . ' |',
    "| Validés seuls | — | $valides |", '']) . "\n");
printf("F1 %s : %s s — %d/%d documents dans le fonds, %d indexés, %d pages perdues, %d erreurs, redépôt %s ; porte %s\n",
    $avecIa ? 'avec IA' : 'sans IA', $duree, count($metas), $attendus, $enDb, count($perdues), count($erreurs),
    $b2['doublons'] === 1 ? 'mis de côté' : 'réingéré', $res['porte']);
exit($franchie ? 0 : 1);
