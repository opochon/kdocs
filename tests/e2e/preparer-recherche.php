<?php

declare(strict_types=1);

/** État exclusif du fonds versionné de l'oracle Recherche. */
$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Socle\Reconstructeur;
use Ged\Socle\Schema as SchemaSocle;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');
$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "préparation recherche : verrou gedv2_test indisponible\n");
    exit(1);
}

try {
    $config = new Config($racine);
    $fonds = str_replace('\\', '/', $config->chemin('STORAGE_ROOT', 'tests/corpus/apercu/documents'));
    if ($config->get('DB_NAME') !== 'gedv2_test' || !str_ends_with($fonds, 'tests/corpus/apercu/documents')) {
        throw new RuntimeException('préparation recherche refusée hors du corpus synthétique de test');
    }
    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaRecherche::installer($pdo);
    remettreEtatFondsE2e($pdo);
    // Les identifiants 201..213 font partie du contrat visuel historique de ce corpus, jamais d'un reste
    // d'un autre fonds. La reconstruction relit les fichiers; leurs .meta.json remettent ensuite ces ids.
    (new Reconstructeur($config))->reconstruire();
    $pdo->exec("UPDATE documents SET id = CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.id')) AS UNSIGNED)");
    $pdo->exec('ALTER TABLE documents AUTO_INCREMENT = 214');
    IndexPleinTexte::depuis($config, $pdo)->synchroniser(null);
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation recherche : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}
