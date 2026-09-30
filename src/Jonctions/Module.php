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

        $api = new DepotApi(
            $jetons,
            new SourceApi($zoneApi, new Travaux($pdo), $pdo),
            $pdo,
            $dossiers,
            new DepotDossier(new DepotFichiers($config), $zoneApi, $pdo),
            (int) $config->get('API_DEPOT_MAX_OCTETS', (string) DepotApi::TAILLE_MAX_DEFAUT),
        );
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
