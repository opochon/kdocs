<?php

declare(strict_types=1);

/**
 * Tâches de fond, courtes et reprenables (planificateur Windows, cron d'hébergement mutualisé).
 *
 *   php tools/tache.php ingestion [--budget=20] [--tours=1] [--limite=200]
 *   php tools/tache.php indexation [--limite=200]
 *
 * Un seul exécutant à la fois (verrou fichier non bloquant : un second appel sort aussitôt).
 * Sortie : une ligne JSON par tour (reçus, doublons, découpés, rangés, erreurs, alertes) ; l'indexation
 * plein texte tourne ICI, hors requête web, par lots courts et reprenables.
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
if (!in_array($tache, ['ingestion', 'indexation'], true)) {
    fwrite(STDERR, "Usage : php tools/tache.php ingestion|indexation [--budget=20] [--tours=1] [--limite=200]\n");
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
    echo json_encode(['tache' => $tache, 'etat' => 'deja_en_cours']), "\n";
    exit(0);
}

$pdo = Base::pdo($config);
Schema::installer($pdo);

// Indexation plein texte : lot court et reprenable, HORS requête web (jamais dans un écran).
if ($tache === 'indexation') {
    try {
        \Ged\Recherche\Schema::installer($pdo);
        $limite = max(1, (int) ($options['limite'] ?? 200));
        $poses = \Ged\Recherche\IndexPleinTexte::depuis($config, $pdo)->synchroniser($limite);
        echo json_encode(['tache' => 'indexation', 'poses' => $poses, 'limite' => $limite], JSON_UNESCAPED_UNICODE), "\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, 'indexation : ' . $e->getMessage() . "\n");
        exit(1);
    }
    flock($verrou, LOCK_UN);
    fclose($verrou);
    exit(0);
}

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

$pilote = Fabrique::pilote($config, $pdo, $depot, Fabrique::classeur($config, $pdo));
for ($i = 1; $i <= $tours; $i++) {
    $bilan = $pilote->tic($budget);
    $bilan['alertes'] = array_merge($alertes, $bilan['alertes']);
    echo json_encode(['tache' => 'ingestion', 'tour' => $i] + $bilan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    // Deux tours à vide de suite avant de s'arrêter : le dossier surveillé ne prend un fichier qu'au second relevé
    // (stabilité), un seul tour à vide arrêtait la tâche avant qu'il entre (défaut trouvé par le banc de charge H-6).
    $vides = ($bilan['recus'] + $bilan['decoupes'] + $bilan['ranges'] === 0) ? ($vides ?? 0) + 1 : 0;
    if ($vides >= 2) {
        break;
    }
    if ($vides === 1) {
        sleep(1);   // le relevé suivant doit voir une date de modification inchangée
    }
}
// Une seule tâche planifiable rend les documents fraîchement rangés recherchables. L'index conserve
// son lot court et reprenable : l'appel suivant complète ce que la limite n'aurait pas couvert.
try {
    \Ged\Recherche\Schema::installer($pdo);
    $limite = max(1, (int) ($options['limite'] ?? 200));
    $poses = \Ged\Recherche\IndexPleinTexte::depuis($config, $pdo)->synchroniser($limite);
    echo json_encode(['tache' => 'ingestion-indexation', 'poses' => $poses, 'limite' => $limite], JSON_UNESCAPED_UNICODE), "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'ingestion / indexation : ' . $e->getMessage() . "\n");
    flock($verrou, LOCK_UN);
    fclose($verrou);
    exit(1);
}
flock($verrou, LOCK_UN);
