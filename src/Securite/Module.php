<?php

declare(strict_types=1);

namespace Ged\Securite;

use Ged\Config;
use Ged\Base;
use Ged\Ui\Vue;
use Slim\App;

/** Point d'entrée autonome du secteur, appelé automatiquement par Kernel. */
final class Module
{
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $dossier = $config->chemin('SECURITE_DOSSIER', 'var/securite');
        $sessions = new RegistreSessions($dossier . '/sessions');
        $audit = new JournalAudit($dossier . '/audit.ndjson');
        $pdo = Base::pdo($config);
        $controleur = new ConnexionController(
            new Authentification($config, $pdo), $sessions, new ProtectionBruteForce($dossier . '/brute-force.json'), $audit, $vue,
        );

        $app->get('/connexion', [$controleur, 'formulaire']);
        $app->post('/connexion', [$controleur, 'connecter']);
        $app->post('/deconnexion', [$controleur, 'deconnecter']);
        $app->add(new GardeCentrale($sessions, $audit, $vue, new DroitsDossiers($pdo)));
    }
}
