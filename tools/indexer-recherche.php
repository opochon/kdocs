<?php

declare(strict_types=1);

/**
 * Indexation du plein texte HORS requête (jamais dans un écran) : le fonds est parcouru par lots courts,
 * chaque appel reprend là où le précédent s'est arrêté.
 *
 *   php tools/indexer-recherche.php                 # un lot (reprise)
 *   php tools/indexer-recherche.php --tout          # tout le fonds d'un coup
 *   php tools/indexer-recherche.php --vider --tout  # reconstruit l'index depuis zéro
 *
 * La base ne peut être que gedv2 (dév) ou gedv2_test (tests) : jamais une base voisine.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Ged\Base;
use Ged\Config;
use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Schema;

$racine = dirname(__DIR__);
$config = new Config($racine);
$nomBase = $config->get('DB_NAME', 'gedv2');
if (!in_array($nomBase, ['gedv2', 'gedv2_test'], true)) {
    fwrite(STDERR, "indexation refusée : base « $nomBase » (seules gedv2 et gedv2_test sont permises)\n");
    exit(1);
}

$tout = in_array('--tout', $argv, true);
$vider = in_array('--vider', $argv, true);
$limite = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--limite=')) {
        $limite = max(1, (int) substr($argument, 9));
    }
}

try {
    $pdo = Base::pdo($config);
    Schema::installer($pdo);
    $index = IndexPleinTexte::depuis($config, $pdo);
    if ($vider) {
        $index->vider();
        echo "Index vidé (reconstruction depuis le dossier).\n";
    }
    $poses = $index->synchroniser($tout ? null : ($limite ?? 200));
    $total = (int) $pdo->query('SELECT COUNT(*) FROM ' . Schema::TABLE)->fetchColumn();

    echo "Base « $nomBase » — documents (re)indexés : $poses ; lignes d'index : $total.\n";
    if (!$tout && $poses > 0) {
        echo "D'autres lots attendent peut-être : relancer la commande, ou --tout.\n";
    }
    exit(0);
} catch (Throwable $erreur) {
    fwrite(STDERR, 'indexation : ' . $erreur->getMessage() . "\n");
    exit(1);
}
