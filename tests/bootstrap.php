<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/TestCase.php';
require_once dirname(__DIR__) . '/app/helpers.php';

// Autoload des classes de test en namespace « Tests\ » (composer ne mappe que « KDocs\Tests\ »).
// Couvre les classes de base non suffixées Test.php (ex. Tests\Feature\ApiTestCase).
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Tests\\', 6) === 0) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
