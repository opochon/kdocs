<?php

declare(strict_types=1);

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Config;

$lot = isset($argv[1]) ? (int) $argv[1] : 0;
if ($lot <= 0) {
    throw new RuntimeException('lot synthétique requis');
}
$config = new Config($racine);
$pdo = Base::pdo($config);
$pages = [];
$statuts = [];
foreach ($pdo->query('SELECT meta, statut FROM documents WHERE supprime_le IS NULL')->fetchAll() as $ligne) {
    $meta = json_decode((string) $ligne['meta'], true) ?: [];
    if ((int) ($meta['origine']['lot'] ?? 0) === $lot) {
        $pages = array_merge($pages, array_map('intval', (array) ($meta['origine']['pages_liste'] ?? [])));
        $statuts[] = (string) $ligne['statut'];
    }
}
sort($pages);
echo json_encode(['pages' => $pages, 'statuts' => $statuts]), PHP_EOL;
