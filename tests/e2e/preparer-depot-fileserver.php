<?php

declare(strict_types=1);

/**
 * Prépare le fonds synthétique du parcours « Dépôt fichier » (lot ged-mfiles-oracle-1) : deux
 * fichiers visibles, un compte administrateur et un compte lecteur (rôles réels), dans gedv2_test.
 * Aucune donnée réelle. Le fonds vit dans var/ (jamais committé), la GED ne fait que le lire.
 */

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\Schema as SchemaSocle;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');
$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "verrou indisponible\n");
    exit(1);
}

try {
    $config = new Config($racine);
    if ($config->get('DB_NAME') !== 'gedv2_test') {
        throw new RuntimeException('base de test imposée');
    }
    $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/depot-fileserver/documents');
    nettoyer($fonds);
    mkdir($fonds . '/2026', 0775, true);
    mkdir($fonds . '/divers', 0775, true);
    file_put_contents($fonds . '/2026/facture-synthetique.txt', "facture synthétique v1\n");
    file_put_contents($fonds . '/divers/notes-synthetiques.txt', "notes synthétiques\n");

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaSecurite::installer($pdo);
    remettreEtatFondsE2e($pdo);

    $admin = 'depot-admin@gedv2.test';
    $lecteur = 'depot-lecteur@gedv2.test';
    $secret = 'MotDePasse-Depot-2026!';
    foreach ([[$admin, 'admin'], [$lecteur, 'lecteur']] as [$identifiant, $role]) {
        $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE empreinte = VALUES(empreinte), role = VALUES(role)')
            ->execute([$identifiant, password_hash($secret, PASSWORD_DEFAULT), $role]);
    }

    echo json_encode([
        'fonds' => $fonds,
        'fichier' => '2026/facture-synthetique.txt',
        'admin' => $admin,
        'lecteur' => $lecteur,
        'mot_de_passe' => $secret,
    ], JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation dépôt fichier : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

function nettoyer(string $chemin): void
{
    if (!is_dir($chemin)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($chemin);
}
