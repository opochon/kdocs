<?php

declare(strict_types=1);

/**
 * Prépare le fonds synthétique du parcours « Classes & métadonnées » (lot ged-mfiles-classes-1) :
 * les classes livrées et les listes nommées dans le fonds, un contrat déposé, un compte
 * administrateur réel. Aucune donnée réelle : le fonds vit dans var/ (jamais committé).
 */

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Listes;
use Ged\Socle\Nomenclature;
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
    $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/classes-metadonnees/documents');
    nettoyer($fonds);
    mkdir($fonds, 0775, true);

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaSecurite::installer($pdo);
    remettreEtatFondsE2e($pdo);

    // Les classes livrées et les vocabulaires vivent dans le fonds : le dossier fait foi.
    (new Nomenclature($config))->assurer();
    (new Listes($config))->assurer();

    // Un contrat déposé, pour la carte dynamique dans la bibliothèque.
    $source = $racine . '/var/oracles/classes-metadonnees/contrat-source.txt';
    if (!is_dir(dirname($source))) {
        mkdir(dirname($source), 0775, true);
    }
    file_put_contents($source, "contrat de maintenance synthétique\n");
    $depot = new DepotFichiers($config);
    $document = $depot->deposer($source, [
        'titre' => 'Contrat de maintenance synthétique',
        'type' => 'Contrat',
        'date' => '2026-09-01',
    ]);

    $admin = 'classes-admin@gedv2.test';
    $secret = 'MotDePasse-Classes-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE empreinte = VALUES(empreinte), role = VALUES(role)')
        ->execute([$admin, password_hash($secret, PASSWORD_DEFAULT), 'admin']);

    echo json_encode([
        'fonds' => $fonds,
        'document' => $document,
        'chemin' => (string) $depot->fiche($document)['chemin_relatif'],
        'admin' => $admin,
        'mot_de_passe' => $secret,
    ], JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation classes-métadonnées : ' . $e->getMessage() . "\n");
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
