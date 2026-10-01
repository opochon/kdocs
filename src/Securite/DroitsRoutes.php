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
        'POST /a-traiter/{id:[0-9]+}/valider' => 'documents:traiter',
        'GET /bibliotheque' => 'documents:lire',
        'GET /recherche' => 'recherche:lire',
        'GET /documents/{id:[0-9]+}' => 'documents:lire',
        'GET /documents/{id:[0-9]+}/apercu' => 'document.voir',
        'GET /documents/{id:[0-9]+}/apercu/fichier' => 'document.voir',
        'GET /documents/{id:[0-9]+}/fichier' => 'document.voir',
        'GET /documents/{id:[0-9]+}/miniature' => 'document.voir',
        'GET /workflows' => 'workflows:lire',
        // Workflows V2-06b : catalogue, éditeur graphique et enregistrement du dessin (lignes signalées
        // hors secteur, à commiter par le juge).
        'GET /workflows/catalogue' => 'workflows:lire',
        'GET /workflows/circuits/{id:[0-9]+}/editeur' => 'workflows:lire',
        'POST /workflows/circuits' => 'workflow.gerer',
        'POST /workflows/circuits/{id:[0-9]+}' => 'workflow.gerer',
        'POST /workflows/regles' => 'workflow.gerer',
        'POST /workflows/regles/{id:[0-9]+}/basculer' => 'workflow.gerer',
        'POST /workflows/gabarits/{nom}' => 'workflow.gerer',
        'POST /workflows/essai' => 'workflow.gerer',
        'POST /workflows/evenements' => 'workflow.executer',
        'POST /workflows/transitions' => 'workflow.transition',
        'GET /application' => 'application:administrer',
        // Socle V2-04 : bibliothèque réelle, vues enregistrées, écran métadonnées de l'administrateur.
        'GET /metadonnees' => 'application:administrer',
        'POST /metadonnees/type' => 'application:administrer',
        'POST /metadonnees/champ' => 'application:administrer',
        'POST /bibliotheque/vue' => 'documents:lire',
        'POST /documents/champ' => 'documents:traiter',
        // API d'entrée sous jeton d'application (secteur Jonctions) : pas de session, donc « publique »
        // au sens de la garde ; la porte réelle est le jeton vérifié par le secteur (V2-08a, signalé).
        'POST /api/v1/depots' => 'publique:api-depot',
        'GET /api/v1/depots/{id:[0-9]+}' => 'publique:api-depot-suivi',
        // Jonctions V2-08b — appairage (sections 1, 2) : la porte des routes `publique:` est le jeton
        // d'appairage vérifié dans le secteur ; les écrans passent, eux, par la session normale.
        'GET /api/v1/appairage/moi' => 'publique:api-appairage-moi',
        'DELETE /api/v1/appairage/moi' => 'publique:api-appairage-moi',
        'POST /api/v1/appairage/jeton' => 'publique:api-appairage',
        'GET /api/v1/dossiers' => 'publique:api-dossiers',
        'GET /appairage' => 'documents:traiter',
        'POST /appairage' => 'documents:traiter',
        // Jonctions V2-08c — la liaison part de la GED : Mon profil (adresse vérifiée, « Relier »,
        // appareils reliés avec révocation). L'ancienne route /appairage redirige vers /profil.
        'GET /profil' => 'documents:traiter',
        'POST /profil/mail' => 'documents:traiter',
        'GET /profil/mail/verifier' => 'documents:traiter',
        'POST /profil/appairage' => 'documents:traiter',
        'GET /application/appareils' => 'documents:traiter',
        'POST /application/appareils/{id:[0-9]+}/revoquer' => 'documents:traiter',
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

        // Suivi d'un travail de l'API d'entrée (identifiant numérique, route sous jeton).
        if (strtoupper($methode) === 'GET' && preg_match('#^/api/v1/depots/[0-9]+$#', $chemin) === 1) {
            return self::PERMISSIONS['GET /api/v1/depots/{id:[0-9]+}'];
        }

        if (strtoupper($methode) === 'GET') {
            foreach (['/apercu', '/apercu/fichier', '/fichier', '/miniature'] as $suffixe) {
                if (preg_match('#^/documents/[0-9]+' . preg_quote($suffixe, '#') . '$#', $chemin) === 1) {
                    return self::PERMISSIONS['GET /documents/{id:[0-9]+}' . $suffixe];
                }
            }
            // Ouvre l'éditeur graphique d'un circuit (Workflows V2-06b).
            if (preg_match('#^/workflows/circuits/[0-9]+/editeur$#', $chemin) === 1) {
                return self::PERMISSIONS['GET /workflows/circuits/{id:[0-9]+}/editeur'];
            }
        }

        // Routes de Workflows paramétrées (une bascule de règle, un gabarit nommé).
        if (strtoupper($methode) === 'POST') {
            if (preg_match('#^/a-traiter/[0-9]+/valider$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /a-traiter/{id:[0-9]+}/valider'];
            }
            // Enregistre le dessin d'un circuit (Workflows V2-06b ; la création exacte est déjà reconnue).
            if (preg_match('#^/workflows/circuits/[0-9]+$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /workflows/circuits/{id:[0-9]+}'];
            }
            if (preg_match('#^/workflows/regles/[0-9]+/basculer$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /workflows/regles/{id:[0-9]+}/basculer'];
            }
            if (preg_match('#^/workflows/gabarits/[A-Za-z0-9_]+$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /workflows/gabarits/{nom}'];
            }
            // Révocation d'un appareil relié (Jonctions V2-08b).
            if (preg_match('#^/application/appareils/[0-9]+/revoquer$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /application/appareils/{id:[0-9]+}/revoquer'];
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
