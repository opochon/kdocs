<?php
declare(strict_types=1);
define('KDOCS_ROOT', dirname(__DIR__));
require KDOCS_ROOT . '/vendor/autoload.php';
require_once KDOCS_ROOT . '/app/helpers.php';
\KDocs\Core\Config::load();

$db = \KDocs\Core\Database::getInstance();
$row = $db->query("SHOW TABLE STATUS LIKE 'documents'")->fetch(PDO::FETCH_ASSOC);
$engine = strtoupper((string) ($row['Engine'] ?? ''));
echo "documents Engine=$engine\n";
if ($engine !== 'INNODB') {
    $db->exec('ALTER TABLE documents ENGINE=InnoDB');
    echo "Converted to InnoDB\n";
} else {
    echo "Already InnoDB\n";
}
