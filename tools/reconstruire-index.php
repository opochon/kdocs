<?php

declare(strict_types=1);

/**
 * Reconstruit l'index documentaire depuis le dossier (porte F12 : la base n'est qu'un index).
 *
 *   php tools/reconstruire-index.php [base] [--forcer]
 *
 * Vide les tables d'index (documents, versions, déplacements), relit le fonds et la corbeille,
 * puis compare le hash d'un export canonique avant/après : le verdict « identique » (code 0) ou
 * « DIFFÉRENT » (code 1) est écrit noir sur blanc. Refuse tant qu'un document vivant est absent
 * du fonds, sauf --forcer. La base est celle de .env, de l'environnement (le harness force
 * gedv2_test), ou l'argument passé.
 */

$racine = dirname(__DIR__);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Config;
use Ged\Socle\Export;
use Ged\Socle\Schema;
use Ged\Socle\Reconstructeur;

$base = null;
$forcer = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--forcer') {
        $forcer = true;
    } elseif (!str_starts_with($arg, '--')) {
        $base = $arg;
    }
}

try {
    if ($base !== null) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $base)) {
            throw new InvalidArgumentException("nom de base invalide : $base");
        }
        putenv("DB_NAME=$base");   // Config lit l'environnement d'abord
    }
    $config = new Config($racine);
    $nomBase = $config->get('DB_NAME', 'gedv2');
    if (!in_array($nomBase, ['gedv2', 'gedv2_test'], true)) {
        throw new RuntimeException("cet outil n’écrit que sur gedv2 ou gedv2_test, pas sur « $nomBase »");
    }
    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    Schema::installer($pdo);

    echo "Reconstruction de l’index « $nomBase » depuis {$config->chemin('STORAGE_ROOT', 'storage/documents')}\n";
    $avant = Export::hacher($pdo);
    $stats = (new Reconstructeur($config))->reconstruire($forcer);
    $apres = Export::hacher($pdo);

    printf("  index avant  : %s\n  index après  : %s\n", $avant, $apres);
    printf("  documents %d (corbeille %d), versions %d\n", $stats['documents'], $stats['corbeille'], $stats['versions']);
    if ($avant !== $apres) {
        echo "  DIFFÉRENT : l’index reconstruit ne rend pas le contenu d’avant — à examiner avant de continuer\n";
        exit(1);
    }
    echo "  identique : la base reconstruite depuis le dossier rend le même contenu\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'reconstruction : ' . $e->getMessage() . "\n");
    exit(2);
}
