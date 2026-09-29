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
        'GET /documents/{id:[0-9]+}/apercu' => 'document.voir',
        'GET /documents/{id:[0-9]+}/apercu/fichier' => 'document.voir',
        'GET /documents/{id:[0-9]+}/fichier' => 'document.voir',
        'GET /documents/{id:[0-9]+}/miniature' => 'document.voir',
        'GET /workflows' => 'workflows:lire',
        'POST /workflows/regles' => 'workflow.gerer',
        'POST /workflows/regles/{id:[0-9]+}/basculer' => 'workflow.gerer',
        'POST /workflows/gabarits/{nom}' => 'workflow.gerer',
        'POST /workflows/essai' => 'workflow.gerer',
        'POST /workflows/evenements' => 'workflow.executer',
        'POST /workflows/transitions' => 'workflow.transition',
        'GET /application' => 'application:administrer',
        'GET /sante' => 'publique:sante',
        'GET /connexion' => 'publique:connexion',
        'POST /connexion' => 'publique:connexion',
        'POST /deconnexion' => 'session:active',
    ];

    /** @return array<string, string> Routes et permissions que la sonde offensive doit couvrir. */
    public static function declarees(): array
    {
        return self::PERMISSIONS;
    }

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

        // Routes de Workflows paramétrées (une bascule de règle, un gabarit nommé).
        if (strtoupper($methode) === 'POST') {
            if (preg_match('#^/workflows/regles/[0-9]+/basculer$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /workflows/regles/{id:[0-9]+}/basculer'];
            }
            if (preg_match('#^/workflows/gabarits/[A-Za-z0-9_]+$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /workflows/gabarits/{nom}'];
            }
        }

        return null;
    }

    public static function estPublique(string $permission): bool
    {
        return str_starts_with($permission, 'publique:');
    }

    /** L'aperçu a son vocabulaire de route, mais ne crée pas une permission supplémentaire. */
    public static function permissionCanonique(string $permission): string
    {
        return $permission === 'document.voir' ? 'documents:lire' : $permission;
    }
}
