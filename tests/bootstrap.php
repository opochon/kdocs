<?php

declare(strict_types=1);

/**
 * Amorce PHPUnit. Plusieurs agents lancent les tests en même temps et gedv2_test est partagée : sans verrou,
 * les setUp des uns effacent les tables des autres (tests « rouges au hasard », vu le 29-09).
 * Un seul exécutant à la fois sur la base de test : les autres ATTENDENT (verrou fichier bloquant), puis passent.
 * Les bancs qui écrivent dans gedv2_test prennent le même verrou. Il vit dans le dossier temporaire du système, pas dans
 * le dépôt : le juge rejoue depuis une copie de travail séparée (git worktree) et doit attendre les agents, et inversement.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$GLOBALS['__verrou_gedv2_test'] = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($GLOBALS['__verrou_gedv2_test'] === false || !flock($GLOBALS['__verrou_gedv2_test'], LOCK_EX)) {
    fwrite(STDERR, "Verrou de la base de test impossible à prendre (gedv2_test.lock du dossier temporaire)\n");
    exit(1);
}
