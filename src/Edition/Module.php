<?php

declare(strict_types=1);

namespace Ged\Edition;

use Ged\Base;
use Ged\Config;
use Ged\Jonctions\Jetons;
use Ged\Securite\DroitsDossiers;
use Ged\Ui\Vue;
use Slim\App;

/**
 * Point d'entrée autonome du secteur Édition depuis le poste, appelé automatiquement par Kernel.
 *
 * L'API sous jeton d'appareil est déclarée « publique » au sens de la garde de session (voir
 * `Ged\Securite\DroitsRoutes`) : la porte réelle est le jeton vérifié dans le secteur, jamais un
 * cookie. La route de récupération d'une copie de secours passe, elle, par la session normale (CSRF et
 * droit `documents:modifier` vérifiés par la garde centrale).
 */
final class Module
{
    /** @param App<\Psr\Container\ContainerInterface> $app */
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $pdo = Base::pdo($config);
        $api = new Api($config, new Jetons($pdo), new DroitsDossiers($pdo));

        // API de l'agent local (jeton d'appareil, hors session).
        $app->get('/api/v1/edition/documents/{id:[0-9]+}', [$api, 'document']);
        $app->get('/api/v1/edition/documents/{id:[0-9]+}/fichier', [$api, 'fichier']);
        $app->post('/api/v1/edition/documents/{id:[0-9]+}/versions', [$api, 'version']);
        $app->post('/api/v1/edition/documents/{id:[0-9]+}/ouverture', [$api, 'ouverture']);
        $app->post('/api/v1/edition/documents/{id:[0-9]+}/signal', [$api, 'signal']);
        $app->post('/api/v1/edition/documents/{id:[0-9]+}/fermeture', [$api, 'fermeture']);
        $app->post('/api/v1/edition/documents/{id:[0-9]+}/secours', [$api, 'secours']);
        $app->get('/api/v1/edition/documents/{id:[0-9]+}/secours', [$api, 'secoursListe']);

        // Fiche : récupérer une copie de secours en nouvelle version (session + CSRF).
        $app->post('/documents/{id:[0-9]+}/secours/recuperer', [$api, 'recupererSecours']);
    }
}
