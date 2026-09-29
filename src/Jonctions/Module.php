<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Fabrique;
use Ged\Ingestion\Travaux;
use Ged\Ui\Vue;
use Slim\App;

/**
 * Point d'entrée autonome du secteur Jonctions, appelé automatiquement par Kernel.
 * L'API d'entrée vérifie son propre jeton (hors session) ; ses deux routes sont déclarées dans
 * `Ged\Securite\DroitsRoutes` comme publiques au sens de la session, la porte réelle étant le jeton.
 */
final class Module
{
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $pdo = Base::pdo($config);
        $api = new DepotApi(
            new Jetons($pdo),
            new SourceApi(Fabrique::zoneTravail($config) . '/api', new Travaux($pdo), $pdo),
            $pdo,
            (int) $config->get('API_DEPOT_MAX_OCTETS', (string) DepotApi::TAILLE_MAX_DEFAUT),
        );

        $app->post('/api/v1/depots', [$api, 'deposer']);
        $app->get('/api/v1/depots/{id:[0-9]+}', [$api, 'etat']);
    }
}
