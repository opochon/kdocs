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

$appPath = parse_url(env('APP_URL', 'http://localhost/kdocs'), PHP_URL_PATH) ?: '/kdocs';

$appPath = rtrim($appPath, '/');

if ($appPath === '') {

    $appPath = '/';

}

// Slim basePath=/kdocs : GET /kdocs (sans slash) ne matche pas la route '/' → 404
if ($appPath !== '/' && $uri === $appPath) {
    header('Location: ' . $appPath . '/', true, 302);
    return true;
}

$servePublic = static function (string $relativePath): bool {

    return PublicAssets::serve($relativePath);

};



// {basePath}/public/* → public/*

if ($appPath !== '/' && preg_match('#^' . preg_quote($appPath, '#') . '/public/(.+)$#', $uri, $m) && $servePublic($m[1])) {
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

