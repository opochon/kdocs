<?php

declare(strict_types=1);

/**
 * Amorce PHPUnit. Plusieurs agents lancent les tests en même temps et gedv2_test est partagée : sans verrou,
 * les setUp des uns effacent les tables des autres (tests « rouges au hasard », vu le 29-09).
 * Un seul exécutant à la fois sur la base de test : les autres ATTENDENT (verrou fichier bloquant), puis passent.
 * Les bancs qui écrivent dans gedv2_test prennent le même verrou (var/gedv2_test.lock).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dossier = dirname(__DIR__) . '/var';
if (!is_dir($dossier)) {
    mkdir($dossier, 0775, true);
}
$GLOBALS['__verrou_gedv2_test'] = fopen($dossier . '/gedv2_test.lock', 'c');
if ($GLOBALS['__verrou_gedv2_test'] === false || !flock($GLOBALS['__verrou_gedv2_test'], LOCK_EX)) {
    fwrite(STDERR, "Verrou de la base de test impossible à prendre (var/gedv2_test.lock)\n");
    exit(1);
}
