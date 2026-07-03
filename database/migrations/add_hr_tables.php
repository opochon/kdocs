<?php
/**
 * Migration PHP : création des tables RH (GAP-033 — dossier RH digital).
 *
 *   hr_employees          — fiche collaborateur
 *   hr_employee_documents — liaison employé ↔ document GED par catégorie
 *
 * Idempotente : CREATE TABLE IF NOT EXISTS.
 * NE PAS exécuter automatiquement — lancée manuellement via CLI de migration.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;

echo "Migration: Création des tables RH (GAP-033)...\n\n";

try {
    $db = Database::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS hr_employees (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT          NULL,
        first_name VARCHAR(100) NOT NULL,
        last_name  VARCHAR(100) NOT NULL,
        email      VARCHAR(255) NULL,
        hired_at   DATE         NULL,
        position   VARCHAR(150) NULL,
        created_at DATETIME     NOT NULL
    )");
    echo "   OK  Table hr_employees créée (ou déjà présente).\n";

    $db->exec("CREATE TABLE IF NOT EXISTS hr_employee_documents (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id INT          NOT NULL,
        document_id INT          NOT NULL,
        category    VARCHAR(100) NOT NULL
                                 COMMENT 'ex. contrat, certificat, salaire',
        created_at  DATETIME     NOT NULL,
        INDEX idx_emp (employee_id),
        INDEX idx_doc (document_id)
    )");
    echo "   OK  Table hr_employee_documents créée (ou déjà présente).\n";

    echo "\nMigration terminée.\n";
} catch (\Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}
