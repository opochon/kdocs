<?php

/**

 * Routeur pour le serveur PHP intégré (dev).

 *

 * Le .htaccess Apache réécrit /kdocs/public/* vers public/* — le built-in

 * ne lit pas .htaccess. Ce fichier reproduit ce comportement.

 *

 * Lancer : php -S localhost:8765 router.php

 */

declare(strict_types=1);



require_once __DIR__ . '/app/helpers.php';

require_once __DIR__ . '/app/Core/PublicAssets.php';



use KDocs\Core\PublicAssets;



$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

// #region agent log
if (getenv('GEDV1_DEBUG_SESSION') === '4af063') {
    $dbgLog = 'F:/DATA/DEVELOPPEMENT/htmleditor_v3/htmleditor/debug-4af063.log';
    @file_put_contents($dbgLog, json_encode([
        'sessionId' => '4af063',
        'hypothesisId' => 'A',
        'location' => 'router.php:entry',
        'message' => 'request',
        'data' => ['uri' => $uri, 'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'],
        'timestamp' => (int) round(microtime(true) * 1000),
        'runId' => 'runtime',
    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
// #endregion

$appPath = parse_url(env('APP_URL', 'http://localhost/kdocs'), PHP_URL_PATH) ?: '/kdocs';

$appPath = rtrim($appPath, '/');

if ($appPath === '') {

    $appPath = '/';

}

// Slim basePath=/kdocs : GET /kdocs (sans slash) ne matche pas la route '/' → 404
if ($appPath !== '/' && $uri === $appPath) {
    header('Location: ' . $appPath . '/', true, 302);
  // #region agent log
    if (getenv('GEDV1_DEBUG_SESSION') === '4af063') {
        $dbgLog = 'F:/DATA/DEVELOPPEMENT/htmleditor_v3/htmleditor/debug-4af063.log';
        @file_put_contents($dbgLog, json_encode([
            'sessionId' => '4af063',
            'hypothesisId' => 'A',
            'location' => 'router.php:redirectBase',
            'message' => 'redirect /kdocs -> /kdocs/',
            'data' => ['uri' => $uri, 'target' => $appPath . '/'],
            'timestamp' => (int) round(microtime(true) * 1000),
            'runId' => 'runtime',
        ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
  // #endregion
    return true;
}

$servePublic = static function (string $relativePath): bool {

    return PublicAssets::serve($relativePath);

};



// {basePath}/public/* → public/*

if ($appPath !== '/' && preg_match('#^' . preg_quote($appPath, '#') . '/public/(.+)$#', $uri, $m) && $servePublic($m[1])) {
    // #region agent log
    if (getenv('GEDV1_DEBUG_SESSION') === '4af063') {
        $dbgLog = 'F:/DATA/DEVELOPPEMENT/htmleditor_v3/htmleditor/debug-4af063.log';
        @file_put_contents($dbgLog, json_encode([
            'sessionId' => '4af063',
            'hypothesisId' => 'A',
            'location' => 'router.php:servePublic',
            'message' => 'asset served',
            'data' => ['uri' => $uri, 'file' => $m[1]],
            'timestamp' => (int) round(microtime(true) * 1000),
            'runId' => 'runtime',
        ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
    // #endregion
    return true;

}



// /public/* (accès direct sans préfixe app)

if (preg_match('#^/public/(.+)$#', $uri, $m) && $servePublic($m[1])) {

    return true;

}



// Fichiers statiques à la racine du dépôt (favicon, etc.)

$local = __DIR__ . $uri;

if ($uri !== '/' && is_file($local)) {

    return false;

}



// Application Slim sous le base path configuré

if ($appPath === '/' || str_starts_with($uri, $appPath) || $uri === '/') {

    require __DIR__ . '/index.php';

    return true;

}



http_response_code(404);

echo 'Not found';

return true;

