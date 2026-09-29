<?php

declare(strict_types=1);

// Serveur de dev (php -S) : les fichiers statiques sont servis tels quels.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';

Ged\Kernel::creer(dirname(__DIR__))->run();
