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
use Slim\App;

/**
 * Point d'entrée autonome du secteur Jonctions, appelé automatiquement par Kernel.
 *
 * L'API d'entrée et l'appairage vérifient leur propre jeton (hors session) ; leurs routes sont déclarées
 * dans `Ged\Securite\DroitsRoutes` comme publiques au sens de la session, la porte réelle étant le jeton.
 * Les écrans (`/appairage`, `/application/appareils`) passent, eux, par la session normale.
 */
final class Module
{
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $pdo = Base::pdo($config);
        $zoneApi = Fabrique::zoneTravail($config) . '/api';

        $jetons = new Jetons($pdo);
        $droits = new DroitsDossiers($pdo);
        $dossiers = new Dossiers($pdo, $droits);
        $appairage = new Appairage($jetons, $pdo, $dossiers, $config->get('GED_NOM', 'GEDv2'));

        $api = new DepotApi(
            $jetons,
            new SourceApi($zoneApi, new Travaux($pdo), $pdo),
            $pdo,
            $dossiers,
            new DepotDossier(new DepotFichiers($config), $zoneApi, $pdo),
            (int) $config->get('API_DEPOT_MAX_OCTETS', (string) DepotApi::TAILLE_MAX_DEFAUT),
        );
        $appairageApi = new AppairageApi($appairage, $jetons);
        $ecran = new EcranJonctions($vue, $appairage, $jetons, $config);

        // API sous jeton (hors session).
        $app->post('/api/v1/depots', [$api, 'deposer']);
        $app->get('/api/v1/depots/{id:[0-9]+}', [$api, 'etat']);
        $app->get('/api/v1/dossiers', [$api, 'dossiers']);
        $app->post('/api/v1/appairage/jeton', [$appairageApi, 'echanger']);
        $app->get('/api/v1/appairage/moi', [$appairageApi, 'moi']);
        $app->delete('/api/v1/appairage/moi', [$appairageApi, 'revoquer']);

        // Écrans sous session normale.
        $app->get('/appairage', [$ecran, 'appairage']);
        $app->post('/appairage', [$ecran, 'accepter']);
        $app->get('/application/appareils', [$ecran, 'appareils']);
        $app->post('/application/appareils/{id:[0-9]+}/revoquer', [$ecran, 'revoquerAppareil']);
    }
}
