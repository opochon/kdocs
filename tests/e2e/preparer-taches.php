<?php

declare(strict_types=1);

/**
 * Amorce du parcours E2E « suivi des traitements » : une tâche suivie synthétique dans le fonds de test,
 * SANS Celery. Base gedv2_test uniquement, aucune donnée réelle.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = new Ged\Config(dirname(__DIR__, 2));
if ($config->get('DB_NAME') !== 'gedv2_test') {
    fwrite(STDERR, "base de test requise\n");
    exit(1);
}
Ged\Base::assurerBase($config);
Ged\Socle\Schema::installer(Ged\Base::pdo($config));

$tache = (new Ged\Socle\Taches($config))->ouvrir('CONSUME_FILE', 'MANUAL_TASK', 'e2e-facture.pdf', 'e2e');
echo json_encode(['id' => $tache['id'], 'task_file_name' => $tache['task_file_name']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
