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
        // Lot ged-pdfa-1 : rattrapage des copies d'archivage PDF/A du fonds (même droit que la reconstruction).
        'POST /application/pdfa-rattrapage' => 'application:administrer',
        'POST /application/pdfa-rattrapage/{id:[0-9]+}/tranche' => 'application:administrer',
        // Lot ged-parite-final-1 : référentiels (émetteurs, emplacements) et configuration applicative
        // modifiables depuis l'écran Application (même droit que les autres réglages d'installation).
        'POST /application/emetteur' => 'application:administrer',
        'POST /application/emplacement' => 'application:administrer',
        'POST /application/configuration' => 'application:administrer',
        // Archivage V2-09b : lecture séparée, scellement irréversible et vérification du fonds.
        'POST /documents/{id:[0-9]+}/sceller' => 'archivage:sceller',
        'GET /documents/{id:[0-9]+}/apercu' => 'document.voir',
        'GET /documents/{id:[0-9]+}/apercu/fichier' => 'document.voir',
        'GET /documents/{id:[0-9]+}/fichier' => 'document.voir',
        // Copie d'archivage PDF/A-2b (lot ged-pdfa-1) : même droit de lecture que le document.
        'GET /documents/{id:[0-9]+}/pdfa' => 'document.voir',
        'GET /documents/{id:[0-9]+}/miniature' => 'document.voir',
        'GET /workflows' => 'workflows:lire',
        // Lot ged-parite-modele-1 : administration des workflows (modèle Paperless), réservée à `workflow.gerer`.
        'GET /workflows/admin' => 'workflow.gerer',
        'POST /workflows/admin' => 'workflow.gerer',
        'GET /workflows/admin/{id:[0-9]+}' => 'workflow.gerer',
        'POST /workflows/admin/{id:[0-9]+}' => 'workflow.gerer',
        'POST /workflows/admin/{id:[0-9]+}/basculer' => 'workflow.gerer',
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
        // Lot ged-parite-modele-2 (secteur Ingestion) : administration du courrier (comptes, règles,
        // journal), réservée aux administrateurs comme le reste d'Application. Lignes signalées hors secteur.
        'GET /courrier' => 'application:administrer',
        'POST /courrier/comptes' => 'application:administrer',
        'GET /courrier/comptes/{id:[0-9]+}' => 'application:administrer',
        'POST /courrier/comptes/{id:[0-9]+}' => 'application:administrer',
        'POST /courrier/comptes/{id:[0-9]+}/basculer' => 'application:administrer',
        'POST /courrier/comptes/{id:[0-9]+}/tester' => 'application:administrer',
        'POST /courrier/comptes/{id:[0-9]+}/traiter' => 'application:administrer',
        'POST /courrier/regles' => 'application:administrer',
        'GET /courrier/regles/{id:[0-9]+}' => 'application:administrer',
        'POST /courrier/regles/{id:[0-9]+}' => 'application:administrer',
        'POST /courrier/regles/{id:[0-9]+}/basculer' => 'application:administrer',
        'POST /courrier/journal/purger' => 'application:administrer',
        'GET /administration/comptes' => 'application:administrer',
        'POST /administration/comptes' => 'application:administrer',
        'GET /administration/groupes' => 'application:administrer',
        'POST /administration/groupes' => 'application:administrer',
        'GET /administration/roles' => 'application:administrer',
        'GET /dossiers/{id:[0-9]+}/droits' => 'dossiers:gerer',
        'POST /dossiers/{id:[0-9]+}/droits' => 'dossiers:gerer',
        'GET /archives' => 'archivage:consulter',
        'POST /archives/verifier' => 'archivage:consulter',
        // DepotFileserver (famille archivage / versions, lot ged-mfiles-oracle-1) : la veille et
        // l'export touchent tout le fonds, réservés à l'administration. Lignes signalées hors secteur.
        'GET /depot-fileserver' => 'application:administrer',
        'POST /depot-fileserver/scanner' => 'application:administrer',
        'POST /depot-fileserver/verifier' => 'application:administrer',
        'POST /depot-fileserver/exporter' => 'application:administrer',
        // Socle V2-04 : bibliothèque réelle, vues enregistrées, écran métadonnées de l'administrateur.
        'GET /metadonnees' => 'application:administrer',
        'POST /metadonnees/type' => 'application:administrer',
        'POST /metadonnees/champ' => 'application:administrer',
        'POST /metadonnees/etiquette' => 'application:administrer',
        // Lot ged-mfiles-classes-1 (famille M-Files nº 1) : listes de valeurs nommées et définition
        // portable des classes. Lignes hors secteur, signalées dans docs/secteurs/classes-metadonnees.md.
        'POST /metadonnees/liste' => 'application:administrer',
        'POST /metadonnees/liste/valeur' => 'application:administrer',
        'POST /metadonnees/definition' => 'application:administrer',
        // Lot ged-parite-modele-3 : suivi des tâches (administration) et administration des vues
        // enregistrées. Lignes hors secteur (Securite), signalées dans docs/secteurs/socle.md.
        'GET /taches' => 'application:administrer',
        'POST /taches/{id:[0-9]+}/accuser' => 'application:administrer',
        'GET /vues' => 'documents:lire',
        'POST /vues' => 'documents:lire',
        'POST /vues/{id:[0-9]+}' => 'documents:lire',
        'POST /bibliotheque/vue' => 'documents:lire',
        // Lot ged-parite-ecrans-1 (Interface) : tableau de bord, journaux (admin), corbeille (admin,
        // restauration seule — GEDv2 ne supprime jamais), réglages de l'utilisateur et accès par numéro
        // d'archive. Lignes hors secteur (Securite), signalées dans docs/secteurs/interface.md.
        'GET /tableau-de-bord' => 'documents:lire',
        'GET /journaux' => 'application:administrer',
        'GET /corbeille' => 'application:administrer',
        'POST /corbeille/{id:[0-9]+}/restaurer' => 'application:administrer',
        'GET /reglages' => 'documents:lire',
        'GET /reglages/{section:[a-z-]+}' => 'documents:lire',
        'POST /reglages' => 'documents:lire',
        'GET /asn' => 'documents:lire',
        'GET /asn/{id:[0-9]+}' => 'documents:lire',
        // Lot ged-parite-masse : modification en masse. La permission globale est vérifiée ici ; le
        // niveau `modifier` sur CHAQUE dossier est vérifié par le service, document par document.
        'POST /bibliotheque/masse' => 'documents:modifier',
        'POST /bibliotheque/masse/{id:[0-9]+}/tranche' => 'documents:modifier',
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
        // API REST des documents (lot ged-api-documents, secteur Jonctions) : jetons LIÉS À UN COMPTE,
        // portées `documents_lecture` / `documents_ecriture` ; la porte réelle est le jeton vérifié par
        // le secteur. Lignes signalées hors secteur, à commiter par le juge.
        'GET /api/v1/documents' => 'publique:api-documents-lecture',
        'POST /api/v1/documents' => 'publique:api-documents-ecriture',
        'GET /api/v1/documents/{id:[0-9]+}' => 'publique:api-documents-lecture',
        'POST /api/v1/documents/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'GET /api/v1/documents/{id:[0-9]+}/fichier' => 'publique:api-documents-lecture',
        'GET /api/v1/documents/{id:[0-9]+}/versions/{numero:[0-9]+}/fichier' => 'publique:api-documents-lecture',
        'GET /api/v1/types' => 'publique:api-documents-lecture',
        'GET /api/v1/etiquettes' => 'publique:api-documents-lecture',
        'GET /api/v1/emetteurs' => 'publique:api-documents-lecture',
        'GET /api/v1/vues' => 'publique:api-documents-lecture',
        // Compléments de parité (lot ged-parite-api-2) : même porte (jeton lié à un compte). Lignes
        // hors secteur (Securite), signalées dans docs/lots/ged-parite-api-2.md.
        'GET /api/v1/champs' => 'publique:api-documents-lecture',
        'GET /api/v1/partages' => 'publique:api-documents-lecture',
        'GET /api/v1/statistiques' => 'publique:api-documents-lecture',
        'GET /api/v1/next_asn' => 'publique:api-documents-lecture',
        'GET /api/v1/autocomplete' => 'publique:api-documents-lecture',
        'POST /api/v1/documents/selection_data' => 'publique:api-documents-lecture',
        'GET /api/v1/documents/{id:[0-9]+}/metadonnees' => 'publique:api-documents-lecture',
        'POST /api/v1/documents/{id:[0-9]+}/email' => 'publique:api-documents-lecture',
        'POST /api/v1/documents/email' => 'publique:api-documents-lecture',
        'POST /api/v1/documents/bulk_edit' => 'publique:api-documents-ecriture',
        'POST /api/v1/documents/bulk_download' => 'publique:api-documents-lecture',
        'POST /api/v1/dossiers/tester' => 'publique:api-documents-ecriture',
        // Édition depuis le poste (lot ged-edition-serveur, F13) : API sous jeton d'appareil (hors
        // session), la porte réelle est le jeton vérifié dans le secteur ; et la route de session qui
        // récupère une copie de secours en nouvelle version.
        'GET /api/v1/edition/documents/{id:[0-9]+}' => 'publique:api-edition',
        'GET /api/v1/edition/documents/{id:[0-9]+}/fichier' => 'publique:api-edition',
        'POST /api/v1/edition/documents/{id:[0-9]+}/versions' => 'publique:api-edition',
        'POST /api/v1/edition/documents/{id:[0-9]+}/ouverture' => 'publique:api-edition',
        'POST /api/v1/edition/documents/{id:[0-9]+}/signal' => 'publique:api-edition',
        'POST /api/v1/edition/documents/{id:[0-9]+}/fermeture' => 'publique:api-edition',
        'POST /api/v1/edition/documents/{id:[0-9]+}/secours' => 'publique:api-edition',
        'GET /api/v1/edition/documents/{id:[0-9]+}/secours' => 'publique:api-edition',
        'POST /documents/{id:[0-9]+}/secours/recuperer' => 'documents:modifier',
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
        // Lot ged-parite-api-3 (Jonctions) : verbes REST des ressources et vues annexes, sous la même
        // porte jeton que DocumentsApi. Lignes hors secteur (Securite), signalées dans
        // docs/lots/ged-parite-api-3.md (rubrique « Lignes hors secteur »).
        'GET /api/v1/etiquettes/{id:[0-9]+}' => 'publique:api-documents-lecture',
        'POST /api/v1/etiquettes' => 'publique:api-documents-ecriture',
        'PUT /api/v1/etiquettes/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'PATCH /api/v1/etiquettes/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'DELETE /api/v1/etiquettes/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        // Correspondants (registre des émetteurs) et emplacements de classement — lot ged-parite-final-1.
        'GET /api/v1/emetteurs/{id:[0-9]+}' => 'publique:api-documents-lecture',
        'POST /api/v1/emetteurs' => 'publique:api-documents-ecriture',
        'PUT /api/v1/emetteurs/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'PATCH /api/v1/emetteurs/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'DELETE /api/v1/emetteurs/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'GET /api/v1/emplacements' => 'publique:api-documents-lecture',
        'GET /api/v1/emplacements/{id:[0-9]+}' => 'publique:api-documents-lecture',
        'POST /api/v1/emplacements' => 'publique:api-documents-ecriture',
        'PUT /api/v1/emplacements/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'PATCH /api/v1/emplacements/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'DELETE /api/v1/emplacements/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'GET /api/v1/types/{id:[0-9]+}' => 'publique:api-documents-lecture',
        'POST /api/v1/types' => 'publique:api-documents-ecriture',
        'PUT /api/v1/types/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'PATCH /api/v1/types/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'DELETE /api/v1/types/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'GET /api/v1/champs/{id:[0-9]+}' => 'publique:api-documents-lecture',
        'POST /api/v1/champs' => 'publique:api-documents-ecriture',
        'PUT /api/v1/champs/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'PATCH /api/v1/champs/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'DELETE /api/v1/champs/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'GET /api/v1/vues/{id:[0-9]+}' => 'publique:api-documents-lecture',
        'POST /api/v1/vues' => 'publique:api-documents-ecriture',
        'PUT /api/v1/vues/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'PATCH /api/v1/vues/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'DELETE /api/v1/vues/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'GET /api/v1/comptes' => 'publique:api-documents-lecture',
        'POST /api/v1/comptes' => 'publique:api-documents-ecriture',
        'GET /api/v1/comptes/{id:[0-9]+}' => 'publique:api-documents-lecture',
        'PUT /api/v1/comptes/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'PATCH /api/v1/comptes/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'DELETE /api/v1/comptes/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'GET /api/v1/groupes' => 'publique:api-documents-lecture',
        'POST /api/v1/groupes' => 'publique:api-documents-ecriture',
        'GET /api/v1/groupes/{id:[0-9]+}' => 'publique:api-documents-lecture',
        'PUT /api/v1/groupes/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'PATCH /api/v1/groupes/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'DELETE /api/v1/groupes/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'POST /api/v1/partages' => 'publique:api-documents-ecriture',
        'GET /api/v1/partages/{id:[0-9]+}' => 'publique:api-documents-lecture',
        'PUT /api/v1/partages/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'PATCH /api/v1/partages/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'DELETE /api/v1/partages/{id:[0-9]+}' => 'publique:api-documents-ecriture',
        'GET /api/v1/documents/{id:[0-9]+}/partages' => 'publique:api-documents-lecture',
        'GET /api/v1/corbeille' => 'publique:api-documents-lecture',
        'POST /api/v1/corbeille' => 'publique:api-documents-ecriture',
        'GET /api/v1/taches' => 'publique:api-documents-lecture',
        'POST /api/v1/taches/acknowledge' => 'publique:api-documents-ecriture',
        'POST /api/v1/taches/run' => 'publique:api-documents-ecriture',
        // Statut temps réel (StatusConsumer de Paperless) en flux SSE léger (lot ged-parite-final-1).
        'GET /api/v1/taches/statut/evenements' => 'publique:api-documents-lecture',
        'GET /api/v1/taches/{id:[0-9]+}' => 'publique:api-documents-lecture',
        'GET /api/v1/courriers/traites/{id:[0-9]+}' => 'publique:api-documents-lecture',
        'GET /api/v1/profil' => 'publique:api-documents-lecture',
        'PATCH /api/v1/profil' => 'publique:api-documents-ecriture',
        'POST /api/v1/jetons' => 'publique:api-documents-ecriture',
        'GET /api/v1/preferences' => 'publique:api-documents-lecture',
        'POST /api/v1/preferences' => 'publique:api-documents-ecriture',
        // Double authentification TOTP (lot ged-parite-api-4) : porte jeton, hors session.
        'GET /api/v1/profil/totp' => 'publique:api-documents-lecture',
        'POST /api/v1/profil/totp' => 'publique:api-documents-ecriture',
        'DELETE /api/v1/profil/totp' => 'publique:api-documents-ecriture',
        'POST /api/v1/comptes/{id:[0-9]+}/deactivate_totp' => 'publique:api-documents-ecriture',
        // Obtention d'un jeton d'API par identifiants (lot ged-parite-api-4) : route publique au sens
        // de la session (elle délivre le jeton), porte réelle = l'authentification vérifiée dans le secteur.
        'POST /api/v1/token' => 'publique:api-token',
        // État système (administrateur) et version installée (tout jeton) — lot ged-parite-api-4.
        'GET /api/v1/statut' => 'publique:api-documents-lecture',
        'GET /api/v1/version' => 'publique:api-documents-lecture',
        // Configuration applicative (lecture) et logo/favicon (publics) — lot ged-parite-api-4.
        'GET /api/v1/configuration' => 'publique:api-documents-lecture',
        'POST /api/v1/configuration' => 'publique:api-documents-ecriture',
        'PUT /api/v1/configuration' => 'publique:api-documents-ecriture',
        'PATCH /api/v1/configuration' => 'publique:api-documents-ecriture',
        'DELETE /api/v1/configuration' => 'publique:api-documents-ecriture',
        'GET /favicon' => 'publique:favicon',
        'GET /logo' => 'publique:app-logo',
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

        // Verbes REST des ressources (lot ged-parite-api-3) : identifiant concret dans le chemin. La
        // route est servie par `Ged\Jonctions\RessourcesApi`, la porte réelle étant le jeton.
        if (preg_match('#^/api/v1/(etiquettes|types|champs|vues|comptes|groupes|emetteurs|emplacements)/[0-9]+$#', $chemin, $ressource) === 1) {
            return self::PERMISSIONS[strtoupper($methode) . ' /api/v1/' . $ressource[1] . '/{id:[0-9]+}'] ?? null;
        }
        // Désactivation TOTP d'un compte (lot ged-parite-api-4) : identifiant concret dans le chemin.
        if (strtoupper($methode) === 'POST' && preg_match('#^/api/v1/comptes/[0-9]+/deactivate_totp$#', $chemin) === 1) {
            return self::PERMISSIONS['POST /api/v1/comptes/{id:[0-9]+}/deactivate_totp'];
        }
        if (preg_match('#^/api/v1/partages/[0-9]+$#', $chemin) === 1) {
            return self::PERMISSIONS[strtoupper($methode) . ' /api/v1/partages/{id:[0-9]+}'] ?? null;
        }
        if (strtoupper($methode) === 'GET' && preg_match('#^/api/v1/documents/[0-9]+/partages$#', $chemin) === 1) {
            return self::PERMISSIONS['GET /api/v1/documents/{id:[0-9]+}/partages'];
        }
        if (strtoupper($methode) === 'GET' && preg_match('#^/api/v1/taches/[0-9]+$#', $chemin) === 1) {
            return self::PERMISSIONS['GET /api/v1/taches/{id:[0-9]+}'];
        }
        if (strtoupper($methode) === 'GET' && preg_match('#^/api/v1/courriers/traites/[0-9]+$#', $chemin) === 1) {
            return self::PERMISSIONS['GET /api/v1/courriers/traites/{id:[0-9]+}'];
        }

        // Courrier (lot ged-parite-modele-2) : compte ou règle paramétré, et ses gestes.
        if (preg_match('#^/courrier/(?:comptes|regles)/[0-9]+(?:/(?:basculer|tester|traiter))?$#', $chemin) === 1) {
            return self::PERMISSIONS['GET /courrier/comptes/{id:[0-9]+}'];
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
        if (strtoupper($methode) === 'POST' && preg_match('#^/application/pdfa-rattrapage/[0-9]+/tranche$#', $chemin) === 1) {
            return self::PERMISSIONS['POST /application/pdfa-rattrapage/{id:[0-9]+}/tranche'];
        }

        // Suivi d'un travail de l'API d'entrée (identifiant numérique, route sous jeton).
        if (strtoupper($methode) === 'GET' && preg_match('#^/api/v1/depots/[0-9]+$#', $chemin) === 1) {
            return self::PERMISSIONS['GET /api/v1/depots/{id:[0-9]+}'];
        }

        if (strtoupper($methode) === 'GET') {
            if (preg_match('#^/dossiers/[0-9]+/droits$#', $chemin) === 1) {
                return self::PERMISSIONS['GET /dossiers/{id:[0-9]+}/droits'];
            }
            foreach (['/apercu', '/apercu/fichier', '/fichier', '/pdfa', '/miniature'] as $suffixe) {
                if (preg_match('#^/documents/[0-9]+' . preg_quote($suffixe, '#') . '$#', $chemin) === 1) {
                    return self::PERMISSIONS['GET /documents/{id:[0-9]+}' . $suffixe];
                }
            }
            // Ouvre l'éditeur graphique d'un circuit (Workflows V2-06b).
            if (preg_match('#^/workflows/circuits/[0-9]+/editeur$#', $chemin) === 1) {
                return self::PERMISSIONS['GET /workflows/circuits/{id:[0-9]+}/editeur'];
            }
            // Édition d'un workflow du modèle Paperless (lot ged-parite-modele-1).
            if (preg_match('#^/workflows/admin/[0-9]+$#', $chemin) === 1) {
                return self::PERMISSIONS['GET /workflows/admin/{id:[0-9]+}'];
            }
        }

        // Routes de Workflows paramétrées (une bascule de règle, un gabarit nommé).
        if (strtoupper($methode) === 'POST') {
            // Tranche d'une modification en masse (lot ged-parite-masse).
            if (preg_match('#^/bibliotheque/masse/[0-9]+/tranche$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /bibliotheque/masse/{id:[0-9]+}/tranche'];
            }
            if (preg_match('#^/dossiers/[0-9]+/droits$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /dossiers/{id:[0-9]+}/droits'];
            }
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
            // Administration d'un workflow Paperless : activer puis enregistrer (lot ged-parite-modele-1).
            if (preg_match('#^/workflows/admin/[0-9]+/basculer$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /workflows/admin/{id:[0-9]+}/basculer'];
            }
            if (preg_match('#^/workflows/admin/[0-9]+$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /workflows/admin/{id:[0-9]+}'];
            }
            // Suivi des traitements et vues enregistrées (lot ged-parite-modele-3).
            if (preg_match('#^/taches/[0-9]+/accuser$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /taches/{id:[0-9]+}/accuser'];
            }
            if (preg_match('#^/vues/[0-9]+$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /vues/{id:[0-9]+}'];
            }
            if (preg_match('#^/workflows/gabarits/[A-Za-z0-9_]+$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /workflows/gabarits/{nom}'];
            }
            // Révocation d'un appareil relié (Jonctions V2-08b).
            if (preg_match('#^/application/appareils/[0-9]+/revoquer$#', $chemin) === 1) {
                return self::PERMISSIONS['POST /application/appareils/{id:[0-9]+}/revoquer'];
            }
        }

        // API d'édition (F13) : identifiant numérique dans le chemin, route sous jeton d'appareil.
        if (preg_match('#^/api/v1/edition/documents/[0-9]+(?:/(?:fichier|versions|ouverture|signal|fermeture|secours))?$#', $chemin) === 1) {
            return self::PERMISSIONS['GET /api/v1/edition/documents/{id:[0-9]+}'];
        }
        // Récupération d'une copie de secours depuis la fiche (session + CSRF, droit modifier).
        if (strtoupper($methode) === 'POST' && preg_match('#^/documents/[0-9]+/secours/recuperer$#', $chemin) === 1) {
            return self::PERMISSIONS['POST /documents/{id:[0-9]+}/secours/recuperer'];
        }

        // API REST des documents (lot ged-api-documents) : identifiant (et numéro de version) dans le chemin.
        if (strtoupper($methode) === 'GET') {
            if (preg_match('#^/api/v1/documents/[0-9]+/versions/[0-9]+/fichier$#', $chemin) === 1) {
                return self::PERMISSIONS['GET /api/v1/documents/{id:[0-9]+}/versions/{numero:[0-9]+}/fichier'];
            }
            if (preg_match('#^/api/v1/documents/[0-9]+/fichier$#', $chemin) === 1) {
                return self::PERMISSIONS['GET /api/v1/documents/{id:[0-9]+}/fichier'];
            }
            // Lot ged-parite-api-2 : fiche fichier d'un document (identifiant concret dans le chemin).
            if (preg_match('#^/api/v1/documents/[0-9]+/metadonnees$#', $chemin) === 1) {
                return self::PERMISSIONS['GET /api/v1/documents/{id:[0-9]+}/metadonnees'];
            }
            if (preg_match('#^/api/v1/documents/[0-9]+$#', $chemin) === 1) {
                return self::PERMISSIONS['GET /api/v1/documents/{id:[0-9]+}'];
            }
        }
        if (strtoupper($methode) === 'POST' && preg_match('#^/api/v1/documents/[0-9]+$#', $chemin) === 1) {
            return self::PERMISSIONS['POST /api/v1/documents/{id:[0-9]+}'];
        }
        // Lot ged-parite-api-2 : envoi d'un document par courriel (identifiant concret dans le chemin).
        if (strtoupper($methode) === 'POST' && preg_match('#^/api/v1/documents/[0-9]+/email$#', $chemin) === 1) {
            return self::PERMISSIONS['POST /api/v1/documents/{id:[0-9]+}/email'];
        }

        // Lot ged-parite-ecrans-1 (Interface) : routes d'écran paramétrées (section de réglages, numéro
        // d'archive, restauration d'une pièce de la corbeille).
        if (strtoupper($methode) === 'GET' && preg_match('#^/reglages/[a-z-]+$#', $chemin) === 1) {
            return self::PERMISSIONS['GET /reglages/{section:[a-z-]+}'];
        }
        if (strtoupper($methode) === 'GET' && preg_match('#^/asn/[0-9]+$#', $chemin) === 1) {
            return self::PERMISSIONS['GET /asn/{id:[0-9]+}'];
        }
        if (strtoupper($methode) === 'POST' && preg_match('#^/corbeille/[0-9]+/restaurer$#', $chemin) === 1) {
            return self::PERMISSIONS['POST /corbeille/{id:[0-9]+}/restaurer'];
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
