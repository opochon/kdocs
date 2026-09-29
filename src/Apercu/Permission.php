<?php

declare(strict_types=1);

namespace Ged\Apercu;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Déclaration de la permission d'aperçu. Le secteur Droits branchera sa garde centrale sur l'attribut
 * `permission` posé ici : toute route d'aperçu exige `document.voir`, rien n'est public par défaut.
 */
final class Permission implements MiddlewareInterface
{
    public const VOIR = 'document.voir';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute('permission', self::VOIR));
    }
}
