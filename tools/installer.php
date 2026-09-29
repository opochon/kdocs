<?php

declare(strict_types=1);

/**
 * Installateur de GEDv2 : pose un schéma neuf, refuse une base peuplée sans --remplacer (et fait alors
 * un dump AVANT de remplacer).
 *
 *   php tools/installer.php [base] --admin=<identifiant> [--motdepasse=<...>] [--remplacer]
 *
 * Le schéma du Socle est posé, puis celui de chaque secteur présent (`Ged\<Secteur>\Schema::installer`),
 * dans l'ordre du Kernel. La base ne peut être que gedv2 (dév) ou gedv2_test (tests/harness) : jamais
 * une autre, jamais une base voisine.
 */

$racine = dirname(__DIR__);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Config;
use Ged\Socle\Schema;

$SECTEURS = ['Securite', 'Socle', 'Ingestion', 'Classement', 'Apercu', 'Recherche', 'Workflows', 'Jonctions'];

$base = null;
$admin = null;
$motdepasse = null;
$remplacer = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--remplacer') {
        $remplacer = true;
    } elseif (str_starts_with($arg, '--admin=')) {
        $admin = substr($arg, 8);
    } elseif (str_starts_with($arg, '--motdepasse=')) {
        $motdepasse = substr($arg, 13);
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
        throw new RuntimeException("l’installateur ne pose que gedv2 ou gedv2_test, pas « $nomBase »");
    }

    // Y a-t-il déjà quelque chose ? (connexion serveur, sans base : elle peut ne pas exister)
    $serveur = new PDO(sprintf('mysql:host=%s;port=%s', $config->get('DB_HOST', '127.0.0.1'), $config->get('DB_PORT', '3307')),
        $config->get('DB_USER', 'root'), $config->get('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = $serveur->query("SELECT table_name FROM information_schema.tables WHERE table_schema = "
        . $serveur->quote($nomBase))->fetchAll(PDO::FETCH_COLUMN);

    if ($tables !== []) {
        if (!$remplacer) {
            throw new RuntimeException("la base $nomBase est peuplée (" . count($tables) . ' tables) :'
                . ' ajouter --remplacer pour la remplacer (un dump sera fait d’abord)');
        }
        $dump = sauvegarder($serveur, $nomBase, $tables, $racine);
        echo "Dump d’avant remplacement : $dump\n";
        foreach ($tables as $t) {
            $serveur->exec("DROP TABLE `$nomBase`.`$t`");
        }
    }

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    foreach ($SECTEURS as $secteur) {
        $schema = 'Ged\\' . $secteur . '\\Schema';
        if (class_exists($schema)) {
            $schema::installer($pdo);
        }
    }
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (count($tables) > 20) {
        throw new RuntimeException(count($tables) . ' tables posées : le départ doit tenir en 20, resserrer le schéma');
    }

    $compte = null;
    if ($admin !== null && $admin !== '') {
        $motdepasse ??= substr(bin2hex(random_bytes(8)), 0, 14);   // affiché une fois, jamais stocké en clair
        $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$admin, password_hash($motdepasse, PASSWORD_DEFAULT), 'admin']);
        $compte = $admin;
    }

    foreach ([['version_schema', '1'], ['installe_le', date('c')], ['base', $nomBase], ['admin', (string) $compte]] as [$cle, $valeur]) {
        $pdo->prepare('INSERT INTO installation (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)')
            ->execute([$cle, $valeur]);
    }

    echo "Installation de « $nomBase » : " . count($tables) . ' tables (' . implode(', ', $tables) . ")\n";
    echo $compte !== null
        ? "Compte admin « $compte », mot de passe : $motdepasse (à noter maintenant, il n’est pas récupérable)\n"
        : "Aucun compte posé (--admin absent)\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'installation : ' . $e->getMessage() . "\n");
    exit(1);
}

/** Dump SQL complet d'une base, en PHP pur : aucun binaire externe, même sortie en mutualisé. */
function sauvegarder(PDO $serveur, string $base, array $tables, string $racine): string
{
    $dossier = $racine . '/var/sauvegardes';
    if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
        throw new RuntimeException("dump : impossible de créer $dossier");
    }
    $fichier = sprintf('%s/%s-%s.sql', $dossier, $base, date('Ymd-His'));
    $sql = "-- Dump $base avant remplacement, " . date('c') . "\nSET NAMES utf8mb4;\n";
    foreach ($tables as $table) {
        $creation = $serveur->query("SHOW CREATE TABLE `$base`.`$table`")->fetch(PDO::FETCH_NUM);
        $sql .= "\nDROP TABLE IF EXISTS `$table`;\n" . $creation[1] . ";\n";
        $lignes = $serveur->query("SELECT * FROM `$base`.`$table`");
        foreach ($lignes as $ligne) {
            $valeurs = array_map(static fn ($v) => $v === null ? 'NULL' : $serveur->quote((string) $v), array_values($ligne));
            $sql .= "INSERT INTO `$table` VALUES (" . implode(', ', $valeurs) . ");\n";
        }
    }
    file_put_contents($fichier, $sql);

    return str_replace('\\', '/', $fichier);
}
