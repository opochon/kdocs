<?php
/**
 * Test MSG Import Service
 */
require_once __DIR__ . '/../vendor/autoload.php';

$svc = new \KDocs\Services\MSGImportService();

echo "=== Test MSG Import Service ===\n\n";
echo "MSG Available: " . ($svc->isAvailable() ? 'YES' : 'NO') . "\n";
echo "Message: " . $svc->getInstallMessage() . "\n";
