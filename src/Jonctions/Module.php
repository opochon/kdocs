<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Fabrique;
use Ged\Ingestion\Travaux;
use Ged\Securite\DroitsDossiers;
use Ged\Socle\DepotFichiers;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Slim\App;

/**
 * Point d'entrée autonome du secteur Jonctions, appelé automatiquement par Kernel.
 *
 * L'API d'entrée et l'appairage vérifient leur propre jeton (hors session) ; leurs routes sont déclarées
 * dans `Ged\Securite\DroitsRoutes` comme publiques au sens de la session, la porte réelle étant le jeton.
 * Les écrans (`/profil`) passent, eux, par la session normale. Depuis V2-08c, la liaison part de la GED :
 * l'ancienne route `/appairage` (poussée par CMM) redirige vers Mon profil.
 */
final class Module
{
    /** @param App<\Psr\Container\ContainerInterface> $app */
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $pdo = Base::pdo($config);
        $zoneApi = Fabrique::zoneTravail($config) . '/api';

        $mailer = Mailer::depuisConfig($config);
        $jetons = new Jetons($pdo);
        $droits = new DroitsDossiers($pdo);
        $dossiers = new Dossiers($pdo, $droits);
        $profil = new Profil($pdo, $mailer);
        $appairage = new Appairage($jetons, $pdo, $dossiers, $profil, $mailer, $config->get('GED_NOM', 'GEDv2'));

        $sourceApi = new SourceApi($zoneApi, new Travaux($pdo), $pdo);
        $depotDossier = new DepotDossier(new DepotFichiers($config), $zoneApi, $pdo);
        $tailleMax = (int) $config->get('API_DEPOT_MAX_OCTETS', (string) DepotApi::TAILLE_MAX_DEFAUT);
        $api = new DepotApi($jetons, $sourceApi, $pdo, $dossiers, $depotDossier, $tailleMax);
        // API REST des documents (lot ged-api-documents) : jetons liés à un compte, portées lecture/écriture.
        $apiDocuments = new DocumentsApi($config, $jetons, $sourceApi, $depotDossier, $droits, $tailleMax);
        // Compléments de l'API (lot ged-parite-api-2), même porte que DocumentsApi (ApiJeton partagé).
        $apiJeton = new ApiJeton($config, $jetons, $droits);
        $pariteApi = new PariteApi($config, $apiJeton);
        $appairageApi = new AppairageApi($appairage, $jetons);
        $ecranProfil = new EcranProfil($vue, $profil, $appairage, $jetons);
        $ecranJonctions = new EcranJonctions($jetons);

        // API sous jeton (hors session).
        $app->post('/api/v1/depots', [$api, 'deposer']);
        $app->get('/api/v1/depots/{id:[0-9]+}', [$api, 'etat']);
        $app->get('/api/v1/dossiers', [$api, 'dossiers']);
        $app->post('/api/v1/appairage/jeton', [$appairageApi, 'echanger']);
        $app->get('/api/v1/appairage/moi', [$appairageApi, 'moi']);
        $app->delete('/api/v1/appairage/moi', [$appairageApi, 'revoquer']);

        // API REST des documents (lot ged-api-documents), même porte que le dépôt : le jeton.
        $app->get('/api/v1/documents', [$apiDocuments, 'lister']);
        $app->post('/api/v1/documents', [$apiDocuments, 'deposer']);
        $app->get('/api/v1/documents/{id:[0-9]+}', [$apiDocuments, 'lire']);
        $app->post('/api/v1/documents/{id:[0-9]+}', [$apiDocuments, 'modifier']);
        $app->get('/api/v1/documents/{id:[0-9]+}/fichier', [$apiDocuments, 'fichier']);
        $app->get('/api/v1/documents/{id:[0-9]+}/versions/{numero:[0-9]+}/fichier', [$apiDocuments, 'fichierVersion']);
        $app->get('/api/v1/types', [$apiDocuments, 'types']);
        $app->get('/api/v1/etiquettes', [$apiDocuments, 'etiquettes']);
        $app->get('/api/v1/emetteurs', [$apiDocuments, 'emetteurs']);
        $app->get('/api/v1/vues', [$apiDocuments, 'vues']);
        // Compléments de parité (lot ged-parite-api-2) : même porte que l'API des documents.
        $app->get('/api/v1/champs', [$pariteApi, 'champs']);
        $app->get('/api/v1/partages', [$pariteApi, 'partages']);

        // Écrans sous session normale : Mon profil porte l'adresse et la liste des appareils reliés.
        $app->get('/profil', [$ecranProfil, 'afficher']);
        $app->post('/profil/mail', [$ecranProfil, 'definirMail']);
        $app->get('/profil/mail/verifier', [$ecranProfil, 'verifierMail']);
        $app->post('/profil/appairage', [$ecranProfil, 'relier']);

        // Ancien parcours URL (CMM poussait l'utilisateur vers la GED) : remplacé, on redirige.
        $app->get('/appairage', static fn (Requete $q, Reponse $r): Reponse => $r->withHeader('Location', '/profil')->withStatus(302));
        $app->post('/appairage', static fn (Requete $q, Reponse $r): Reponse => $r->withHeader('Location', '/profil')->withStatus(303));
        $app->get('/application/appareils', [$ecranJonctions, 'appareils']);
        $app->post('/application/appareils/{id:[0-9]+}/revoquer', [$ecranJonctions, 'revoquerAppareil']);
    }
}
