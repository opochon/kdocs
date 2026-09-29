<?php

declare(strict_types=1);

/**
 * Tâches de fond, courtes et reprenables (planificateur Windows, cron d'hébergement mutualisé).
 *
 *   php tools/tache.php ingestion [--budget=20] [--tours=1]
 *
 * Un seul exécutant à la fois (verrou fichier non bloquant : un second appel sort aussitôt).
 * Sortie : une ligne JSON par tour (reçus, doublons, découpés, rangés, erreurs, alertes).
 */

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Fabrique;
use Ged\Ingestion\Schema;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = [];
$tache = '';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(\w+)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } else {
        $tache = $arg;
    }
}
if ($tache !== 'ingestion') {
    fwrite(STDERR, "Usage : php tools/tache.php ingestion [--budget=20] [--tours=1]\n");
    exit(64);
}
$budget = max(1, (int) ($options['budget'] ?? 20));
$tours = max(1, (int) ($options['tours'] ?? 1));

$config = new Config(dirname(__DIR__));
$travail = Fabrique::zoneTravail($config);
if (!is_dir($travail)) {
    mkdir($travail, 0775, true);
}
$verrou = fopen($travail . '/tache.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) {
    echo json_encode(['tache' => 'ingestion', 'etat' => 'deja_en_cours']), "\n";
    exit(0);
}

$pdo = Base::pdo($config);
Schema::installer($pdo);

// Le dépôt est fourni par le secteur Socle ; tant qu'il n'existe pas, les pièces prêtes attendent sans rien perdre.
$depot = null;
$alertes = [];
if (class_exists(\Ged\Socle\DepotFichiers::class)) {
    try {
        $depot = new \Ged\Socle\DepotFichiers($config);
    } catch (\Throwable $e) {
        $alertes[] = 'dépôt indisponible : ' . $e->getMessage();
    }
} else {
    $alertes[] = 'dépôt absent (secteur Socle) : les pièces prêtes attendent';
}

$pilote = Fabrique::pilote($config, $pdo, $depot);
for ($i = 1; $i <= $tours; $i++) {
    $bilan = $pilote->tic($budget);
    $bilan['alertes'] = array_merge($alertes, $bilan['alertes']);
    echo json_encode(['tache' => 'ingestion', 'tour' => $i] + $bilan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    if ($bilan['recus'] + $bilan['decoupes'] + $bilan['ranges'] === 0) {
        break;   // plus rien à faire
    }
}
flock($verrou, LOCK_UN);
