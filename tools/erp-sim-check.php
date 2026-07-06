<?php
/**
 * Vérification du lien d'évidence ERP Connect (spec Playwright erp-connect).
 * Usage : php tools/erp-sim-check.php <documentId>  → JSON de la ligne erp_links (ou null)
 */
declare(strict_types=1);

define('KDOCS_ROOT', dirname(__DIR__));
require KDOCS_ROOT . '/vendor/autoload.php';
require_once KDOCS_ROOT . '/app/helpers.php';
\KDocs\Core\Config::load();

$docId = (int) ($argv[1] ?? 0);
$stmt = \KDocs\Core\Database::getInstance()->prepare(
    'SELECT external_id, external_ref, status, validation_status, validated_by_name, validated_at
     FROM erp_links WHERE document_id = ? AND connector = ?'
);
$stmt->execute([$docId, 'ktime']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode($row === false ? null : $row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
