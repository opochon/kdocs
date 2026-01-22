<?php
require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

try {
    $db->exec('ALTER TABLE workflow_triggers ADD COLUMN `match` VARCHAR(255) NULL COMMENT "Expression de correspondance"');
    echo "OK: Colonne match ajoutée\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "EXISTS: Colonne match existe déjà\n";
    } else {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}
