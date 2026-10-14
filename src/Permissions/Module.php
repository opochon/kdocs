<?php

declare(strict_types=1);

namespace Ged\Permissions;

use Ged\Base;
use Ged\Config;
use Ged\Ui\Ecrans;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Slim\App;

/**
 * Point d'entrée du secteur, appelé par le Kernel comme les autres modules : l'écran `/permissions`
 * liste les règles nommées et permet d'en créer ou d'en basculer, jamais de les supprimer. Toutes les
 * routes sont déclarées dans `Ged\Securite\DroitsRoutes` (`application:administrer`) : la garde refuse
 * par défaut, et chaque mutation exige le jeton CSRF et une session.
 */
final class Module
{
    /** @param App<\Psr\Container\ContainerInterface> $app */
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $ecran = new Ecran($config, Base::pdo($config));

        $app->get('/permissions', function (Requete $requete, Reponse $reponse) use ($ecran, $vue): Reponse {
            try {
                $donnees = $ecran->donnees();
            } catch (\RuntimeException $e) {
                return $vue->rendre($reponse, 'erreur.twig', [
                    'code' => 503,
                    'etape' => 'GET /permissions',
                    'cause' => $e->getMessage(),
                    'reference' => substr(hash('sha256', 'permissions:' . $e->getMessage()), 0, 10),
                ], 503);
            }
            $donnees['csrf'] = Ecrans::jetonCsrf($requete);

            return $vue->rendre($reponse, 'ecrans/permissions.twig', $donnees);
        });

        $app->post('/permissions/regles', function (Requete $requete, Reponse $reponse) use ($ecran, $vue): Reponse {
            try {
                $ecran->creer((array) ($requete->getParsedBody() ?: []), self::auteur($requete));
            } catch (\RuntimeException $e) {
                return self::refus($vue, $reponse, 'POST /permissions/regles', $e->getMessage());
            }

            return $reponse->withHeader('Location', '/permissions')->withStatus(303);
        });

        $app->post('/permissions/regles/{id:[0-9]+}/basculer', function (Requete $requete, Reponse $reponse, array $args) use ($ecran, $vue): Reponse {
            $corps = (array) ($requete->getParsedBody() ?: []);
            try {
                $ecran->basculer((int) $args['id'], (string) ($corps['actif'] ?? '') === '1');
            } catch (\RuntimeException $e) {
                return self::refus($vue, $reponse, 'POST /permissions/regles/' . (int) $args['id'] . '/basculer', $e->getMessage());
            }

            return $reponse->withHeader('Location', '/permissions')->withStatus(303);
        });
    }

    /** Un refus de règle se NOMME (étape, cause, référence) sur toutes les environnements. */
    private static function refus(Vue $vue, Reponse $reponse, string $etape, string $cause): Reponse
    {
        return $vue->rendre($reponse, 'erreur.twig', [
            'code' => 400,
            'etape' => $etape,
            'cause' => $cause,
            'reference' => substr(hash('sha256', 'permissions:' . $etape . ':' . $cause), 0, 10),
        ], 400);
    }

    private static function auteur(Requete $requete): string
    {
        $session = $requete->getAttribute('ged.session');

        return is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';
    }
}
