<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Config;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Slim\App;
use Throwable;

/**
 * Routes de l'écran « À traiter ». Le GET est enregistré par le Kernel (les six écrans maîtres) ; la
 * SEULE mutation de cet écran est la validation d'une pièce, en POST et sous CSRF (le jeton est vérifié
 * et consommé par la garde centrale avant d'arriver ici).
 */
final class Module
{
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $app->post('/a-traiter/{id:[0-9]+}/valider', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $session = $req->getAttribute('ged.session');
            $auteur = is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';

            try {
                $atraiter = ATraiter::depuisRacine($config->racine());
                $atraiter->valider((int) $args['id'], $corps, $auteur);
                $suivant = $atraiter->suivant((int) $args['id']);
            } catch (Throwable $e) {
                return $vue->rendre($rep, 'erreur.twig', [
                    'code' => 422,
                    'etape' => 'POST /a-traiter/{id}/valider',
                    'cause' => $e->getMessage(),
                    'reference' => substr(hash('sha256', 'atraiter:' . $e->getMessage() . microtime()), 0, 10),
                ], 422);
            }

            return $rep->withHeader('Location', $suivant !== null ? '/a-traiter?doc=' . $suivant : '/a-traiter')->withStatus(303);
        });
    }
}
