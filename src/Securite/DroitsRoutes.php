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
        // V2-12a : retouche de pièce dans À traiter (pivoter, scinder, fusionner, retirer, annuler).
        'POST /a-traiter/{id:[0-9]+}/pages/tourner' => 'documents:traiter',
        'POST /a-traiter/{id:[0-9]+}/pages/scinder' => 'documents:traiter',
        'POST /a-traiter/{id:[0-9]+}/pages/fusionner' => 'documents:traiter',
        'POST /a-traiter/{id:[0-9]+}/pages/retirer' => 'documents:traiter',
        'POST /a-traiter/{id:[0-9]+}/pages/annuler' => 'documents:traiter',
        // Interface V2-10a (mobile) : dépôt par photo de l'écran « Déposer » — même chaîne d'ingestion
        // que le dépôt de bureau : la décision V2-13 l'ouvre à l'Utilisateur.
        'GET /deposer' => 'documents:deposer',
        'POST /deposer' => 'documents:deposer',
        'GET /bibliotheque' => 'documents:lire',
        'GET /recherche' => 'recherche:lire',
        'GET /documents/{id:[0-9]+}' => 'documents:lire',
        'POST /documents/{id:[0-9]+}/versions' => 'documents:modifier',
        // V2-12c : restaurer vN cree une nouvelle version (jamais d'ecrasement), vue depuis Recherche, lot.
        'POST /documents/{id:[0-9]+}/versions/{numero:[0-9]+}/restaurer' => 'documents:modifier',
        'POST /recherche/vue' => 'documents:lire',
        'POST /a-traiter/valider-lot' => 'documents:traiter',
        // V2-12d : notes et liens relevent du role Utilisateur (droit de dossier Lecture, voir GardeCentrale).
        'POST /documents/{id:[0-9]+}/notes' => 'documents:lire',
        'POST /documents/{id:[0-9]+}/liens' => 'documents:lire',
        // Partage interne (et sa revocation) : geste de lecteur ; le controleur verifie que le destinataire
        // lit deja le dossier. Le lien externe a sa route propre ci-dessous.
        'POST /documents/{id:[0-9]+}/partages' => 'documents:lire',
        // Lien externe (creation et revocation) : geste propre, reserve au Manager ; jusqu'a V2-13 seul
        // l'administrateur (permission '*') porte documents:partager-externe.
        'POST /documents/{id:[0-9]+}/partages/externe' => 'documents:partager-externe',
        'GET /partages-avec-moi' => 'documents:lire',
        // Lien externe : seules ces deux routes sont publiques ; le controleur verifie le jeton hache.
        'GET /partages/{jeton:[A-Za-z0-9_-]+}' => 'publique:partage-externe',
        'GET /partages/{jeton:[A-Za-z0-9_-]+}/fichier' => 'publique:partage-externe',
        'POST /application/reconstruction-index' => 'application:administrer',
        'POST /application/reconstruction-index/{id:[0-9]+}/tranche' => 'application:administrer',
        // Archivage V2-09b : lecture séparée, scellement irréversible et vérification du fonds.
        'POST /documents/{id:[0-9]+}/sceller' => 'archivage:sceller',
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
        'GET /administration/comptes' => 'application:administrer',
        'POST /administration/comptes' => 'application:administrer',
        'GET /administration/groupes' => 'application:administrer',
        'POST /administration/groupes' => 'application:administrer',
        'GET /administration/roles' => 'application:administrer',
        'GET /dossiers/{id:[0-9]+}/droits' => 'dossiers:gerer',
        'POST /dossiers/{id:[0-9]+}/droits' => 'dossiers:gerer',
        'GET /archives' => 'archivage:consulter',
        'POST /archives/verifier' => 'archivage:consulter',
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

        // Lien externe V2-12d (jeton seul, aucune session).
        if (strtoupper($methode) === 'GET' && preg_match('#^/partages/[A-Za-z0-9_-]+(/fichier)?$#', $chemin, $m) === 1) {
            return self::PERMISSIONS['GET /partages/{jeton:[A-Za-z0-9_-]+}' . ($m[1] ?? '')];
        }
        if (strtoupper($methode) === 'POST' && preg_match('#^/documents/[0-9]+/partages/externe$#', $chemin) === 1) {
            return self::PERMISSIONS['POST /documents/{id:[0-9]+}/partages/externe'];
        }
        if (strtoupper($methode) === 'POST' && preg_match('#^/documents/[0-9]+/(notes|liens|partages)$#', $chemin, $m) === 1) {
            return self::PERMISSIONS['POST /documents/{id:[0-9]+}/' . $m[1]];
        }
        if (strtoupper($methode) === 'POST' && preg_match('#^/application/reconstruction-index/[0-9]+/tranche$#', $chemin) === 1) {
            return self::PERMISSIONS['POST /application/reconstruction-index/{id:[0-9]+}/tranche'];
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
            if (preg_match('#^/documents/[0-9]+/versions$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /documents/{id:[0-9]+}/versions'];
            }
            if (preg_match('#^/documents/[0-9]+/versions/[0-9]+/restaurer$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /documents/{id:[0-9]+}/versions/{numero:[0-9]+}/restaurer'];
            }
            if (preg_match('#^/documents/[0-9]+/sceller$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /documents/{id:[0-9]+}/sceller'];
            }
            if (preg_match('#^/a-traiter/[0-9]+/pages/(tourner|scinder|fusionner|retirer|annuler)$#', $chemin, $m) === 1) {
                return self::PERMISSIONS['POST /a-traiter/{id:[0-9]+}/pages/' . $m[1]];
            }
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

    /** Niveau ACL requis par une route documentaire ; null si elle n'adresse pas encore de document. */
    public static function niveauDossierPour(string $methode, string $chemin): ?string
    {
        if (preg_match('#^/documents/[0-9]+/sceller$#', $chemin) === 1) {
            return 'gerer';
        }
        if (preg_match('#^/documents/[0-9]+/(?:notes|liens|partages)(?:/externe)?$#', $chemin) === 1) {
            return 'lire';
        }
        if (preg_match('#^/dossiers/[0-9]+/droits$#', $chemin) === 1) {
            return 'gerer';
        }
        if (preg_match('#^/documents/[0-9]+/(?:versions(?:/[0-9]+/restaurer)?)$#', $chemin) === 1) {
            return 'modifier';
        }
        if (preg_match('#^/documents/[0-9]+(?:/|$)#', $chemin) === 1) {
            return strtoupper($methode) === 'GET' || strtoupper($methode) === 'HEAD' ? 'lire' : 'modifier';
        }

        return null;
    }
}
