<?php

declare(strict_types=1);

namespace Ged;

use Ged\Ui\Ecrans;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Slim\App;
use Slim\Factory\AppFactory;
use Throwable;

/**
 * Assemblage de l'application. Un seul endroit décide des routes et du traitement des erreurs.
 */
final class Kernel
{
    public static function creer(string $racine): App
    {
        $config = new Config($racine);
        $vue = new Vue($racine . '/templates');
        $app = AppFactory::create();
        $app->addRoutingMiddleware();

        // Une erreur se NOMME (étape, cause, référence transmissible), jamais un 500 muet.
        $erreurs = $app->addErrorMiddleware($config->get('APP_ENV', 'dev') === 'dev', true, true);
        $erreurs->setDefaultErrorHandler(function (Requete $req, Throwable $e, bool $details) use ($app, $vue): Reponse {
            $code = $e instanceof \Slim\Exception\HttpException ? $e->getCode() : 500;
            $reference = substr(hash('sha256', $e::class . $e->getMessage() . microtime()), 0, 10);
            error_log(sprintf('[GEDv2 %s] %s %s : %s (%s:%d)', $reference, $req->getMethod(), $req->getUri()->getPath(),
                $e->getMessage(), $e->getFile(), $e->getLine()));

            return $vue->rendre($app->getResponseFactory()->createResponse(), 'erreur.twig', [
                'code' => $code,
                'etape' => $req->getMethod() . ' ' . $req->getUri()->getPath(),
                'cause' => $code === 404 ? 'Cette page n’existe pas.' : ($details ? $e->getMessage() : 'Erreur interne.'),
                'reference' => $reference,
            ], $code);
        });

        $ecrans = new Ecrans($vue);
        $app->get('/', fn (Requete $q, Reponse $r) => $r->withHeader('Location', '/a-traiter')->withStatus(302));
        $app->get('/a-traiter', [$ecrans, 'aTraiter']);
        $app->get('/bibliotheque', [$ecrans, 'bibliotheque']);
        $app->get('/recherche', [$ecrans, 'recherche']);
        $app->get('/documents/{id:[0-9]+}', [$ecrans, 'document']);
        $app->get('/workflows', [$ecrans, 'workflows']);
        $app->get('/application', [$ecrans, 'application']);
        $app->get('/sante', function (Requete $q, Reponse $r) use ($racine): Reponse {
            // Garde-fou du harness : prouve QUEL code et QUELLE base répondent.
            $r->getBody()->write((string) json_encode(['app' => 'gedv2', 'racine' => str_replace('\\', '/', $racine),
                'base' => (new Config($racine))->get('DB_NAME', 'gedv2'), 'php' => PHP_VERSION]));

            return $r->withHeader('Content-Type', 'application/json');
        });

        return $app;
    }
}
