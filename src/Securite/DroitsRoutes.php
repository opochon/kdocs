<?php

declare(strict_types=1);

namespace Ged\Securite;

/**
 * Registre unique des permissions HTTP. Une route absente de cette liste est interdite :
 * cela évite qu'une nouvelle fonctionnalité devienne publique par oubli.
 */
final class DroitsRoutes
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'GET /' => 'documents:lire',
        'GET /a-traiter' => 'documents:traiter',
        'GET /bibliotheque' => 'documents:lire',
        'GET /recherche' => 'recherche:lire',
        'GET /documents/{id:[0-9]+}' => 'documents:lire',
        'GET /documents/{id:[0-9]+}/apercu' => 'documents:lire',
        'GET /documents/{id:[0-9]+}/apercu/fichier' => 'documents:lire',
        'GET /documents/{id:[0-9]+}/fichier' => 'documents:lire',
        'GET /documents/{id:[0-9]+}/miniature' => 'documents:lire',
        'GET /workflows' => 'workflows:lire',
        'GET /application' => 'application:administrer',
        'GET /sante' => 'publique:sante',
        'GET /connexion' => 'publique:connexion',
        'POST /connexion' => 'publique:connexion',
        'POST /deconnexion' => 'session:active',
    ];

    public static function permissionPour(string $methode, string $chemin): ?string
    {
        $cle = strtoupper($methode) . ' ' . $chemin;
        if (isset(self::PERMISSIONS[$cle])) {
            return self::PERMISSIONS[$cle];
        }

        if (strtoupper($methode) === 'GET' && preg_match('#^/documents/[0-9]+$#', $chemin) === 1) {
            return self::PERMISSIONS['GET /documents/{id:[0-9]+}'];
        }

        if (strtoupper($methode) === 'GET') {
            foreach (['/apercu', '/apercu/fichier', '/fichier', '/miniature'] as $suffixe) {
                if (preg_match('#^/documents/[0-9]+' . preg_quote($suffixe, '#') . '$#', $chemin) === 1) {
                    return self::PERMISSIONS['GET /documents/{id:[0-9]+}' . $suffixe];
                }
            }
        }

        return null;
    }

    public static function estPublique(string $permission): bool
    {
        return str_starts_with($permission, 'publique:');
    }
}
