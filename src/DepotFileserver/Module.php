<?php

declare(strict_types=1);

namespace Ged\DepotFileserver;

use Ged\Config;
use Ged\Ui\Ecrans;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Slim\App;
use Throwable;

/**
 * Routes du secteur DepotFileserver : l'écran « Dépôt fichier » et ses trois gestes courts
 * (scanner, vérifier la garantie, exporter une arborescence classique). Toute erreur est NOMMÉE et
 * renvoyée à l'écran, jamais un 500 muet.
 *
 * Réservé à l'administration (`application:administrer`) : la veille et l'export touchent tout le fonds.
 */
final class Module
{
    /** @param App<\Psr\Container\ContainerInterface> $app */
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $app->get('/depot-fileserver', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            try {
                $donnees = (new EcranDepot($config))->donnees($req->getQueryParams());
            } catch (Throwable $e) {
                $donnees = ['ecran' => 'depot-fileserver', 'racine' => '', 'garantie' => '',
                    'resume' => ['fichiers' => 0, 'presents' => 0, 'absents' => 0, 'versions_garanties' => 0, 'instance' => ''],
                    'journal' => [], 'message' => null, 'erreur' => 'Dépôt fichier : ' . $e->getMessage()];
            }
            $donnees['csrf'] = Ecrans::jetonCsrf($req);

            return $vue->rendre($rep, 'ecrans/depot-fileserver.twig', $donnees);
        });

        $app->post('/depot-fileserver/scanner', function (Requete $req, Reponse $rep) use ($config): Reponse {
            if (!self::confirme($req)) {
                return self::rediriger($rep, '/depot-fileserver', ['erreur' => 'Scan : confirmation absente.']);
            }
            try {
                $stats = Veille::depuisConfig($config)->passer();

                return self::rediriger($rep, '/depot-fileserver', [
                    'fait' => 'scan', 'fichiers' => $stats['fichiers'], 'nouveaux' => $stats['nouveaux'],
                    'modifies' => $stats['modifies'], 'deplaces' => $stats['deplaces'], 'supprimes' => $stats['supprimes'],
                ]);
            } catch (Throwable $e) {
                return self::rediriger($rep, '/depot-fileserver', ['erreur' => 'Scan : ' . $e->getMessage()]);
            }
        });

        $app->post('/depot-fileserver/verifier', function (Requete $req, Reponse $rep) use ($config): Reponse {
            if (!self::confirme($req)) {
                return self::rediriger($rep, '/depot-fileserver', ['erreur' => 'Vérification : confirmation absente.']);
            }
            try {
                $verification = Veille::depuisConfig($config)->verifierGarantie();

                return self::rediriger($rep, '/depot-fileserver', [
                    'fait' => 'verification', 'verifies' => $verification['verifies'], 'manquants' => count($verification['manquants']),
                ]);
            } catch (Throwable $e) {
                return self::rediriger($rep, '/depot-fileserver', ['erreur' => 'Vérification : ' . $e->getMessage()]);
            }
        });

        $app->post('/depot-fileserver/exporter', function (Requete $req, Reponse $rep) use ($config): Reponse {
            if (!self::confirme($req)) {
                return self::rediriger($rep, '/depot-fileserver', ['erreur' => 'Export : confirmation absente.']);
            }
            try {
                $racine = $config->chemin('STORAGE_ROOT', 'storage/documents');
                $destination = $config->chemin('DEPOT_EXPORT', 'var/export-depot-fileserver');
                $resultat = (new Reversibilite($racine))->exporter($destination);

                return self::rediriger($rep, '/depot-fileserver', ['fait' => 'export', 'exportes' => $resultat['exportes']]);
            } catch (Throwable $e) {
                return self::rediriger($rep, '/depot-fileserver', ['erreur' => 'Export : ' . $e->getMessage()]);
            }
        });
    }

    /** Le geste n'agit que sur confirmation explicite : une charge malveillante ne déclenche jamais un scan. */
    private static function confirme(Requete $req): bool
    {
        $corps = $req->getParsedBody();

        return is_array($corps) && trim((string) ($corps['confirmation'] ?? '')) !== '';
    }

    /** @param array<string, int|string> $parametres */
    private static function rediriger(Reponse $rep, string $chemin, array $parametres): Reponse
    {
        return $rep->withHeader('Location', $chemin . '?' . http_build_query($parametres))->withStatus(303);
    }
}
