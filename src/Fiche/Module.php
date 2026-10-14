<?php

declare(strict_types=1);

namespace Ged\Fiche;

use Ged\Config;
use Ged\Socle\DepotFichiers;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Slim\App;
use Throwable;

/**
 * Routes de la FICHE & DU RENDU (famille M-Files nº 5) : renommer le titre de l'objet, renommer le
 * fichier, verrouiller / libérer (check-out). Chaque route est déclarée dans `Ged\Securite\DroitsRoutes`
 * (permission + niveau ACL) : la garde centrale applique session, permission, droit de dossier et CSRF
 * avant d'arriver ici ; le service refuse ENCORE métier (document scellé, document inconnu, verrou
 * d'autrui). Une erreur se nomme (étape, cause), jamais un 500 muet.
 *
 * Enregistré par `src/Kernel.php` (une ligne, signalée dans `docs/secteurs/fiche-rendu.md`).
 */
final class Module
{
    /** @param App<\Psr\Container\ContainerInterface> $app */
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $app->post('/documents/{id:[0-9]+}/titre', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $id = (int) ($args['id'] ?? 0);
            try {
                $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
                $titre = trim((string) ($corps['titre'] ?? ''));
                if ($titre === '' || mb_strlen($titre) > 512) {
                    throw new \RuntimeException('titre : un titre non vide est requis (512 caractères maximum)');
                }
                (new DepotFichiers($config))->mettreAJour($id, ['titre' => $titre], self::auteur($req), self::compte($req), self::administrateur($req));

                return self::rediriger($rep, $id, 'Titre de l’objet modifié.');
            } catch (Throwable $e) {
                return self::erreur($vue, $rep, 'POST /documents/{id}/titre', $id, $e);
            }
        });

        $app->post('/documents/{id:[0-9]+}/nom-fichier', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $id = (int) ($args['id'] ?? 0);
            try {
                $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
                (new Nom($config))->renommer($id, (string) ($corps['nom'] ?? ''), self::auteur($req), self::compte($req), self::administrateur($req));

                return self::rediriger($rep, $id, 'Nom du fichier modifié.');
            } catch (Throwable $e) {
                return self::erreur($vue, $rep, 'POST /documents/{id}/nom-fichier', $id, $e);
            }
        });

        $app->post('/documents/{id:[0-9]+}/verrou', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $id = (int) ($args['id'] ?? 0);
            try {
                $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
                $verrou = new Verrou($config);
                if ((string) ($corps['action'] ?? 'poser') === 'retirer') {
                    $verrou->retirer($id, self::auteur($req), self::administrateur($req));

                    return self::rediriger($rep, $id, 'Verrou libéré (check-in).');
                }
                $verrou->poser($id, self::auteur($req), (int) ($corps['minutes'] ?? 30));

                return self::rediriger($rep, $id, 'Document verrouillé (check-out en cours).');
            } catch (Throwable $e) {
                return self::erreur($vue, $rep, 'POST /documents/{id}/verrou', $id, $e);
            }
        });
    }

    private static function rediriger(Reponse $rep, int $id, string $message): Reponse
    {
        return $rep->withHeader('Location', '/documents/' . $id . '?fiche_succes=' . rawurlencode($message))->withStatus(303);
    }

    private static function erreur(Vue $vue, Reponse $rep, string $etape, int $id, Throwable $e): Reponse
    {
        $reference = substr(hash('sha256', 'fiche:' . $etape . ':' . $e->getMessage() . microtime()), 0, 10);
        error_log(sprintf('[GEDv2 fiche %s] document %d : %s (%s:%d)', $reference, $id, $e->getMessage(), $e->getFile(), $e->getLine()));

        return $vue->rendre($rep, 'erreur.twig', [
            'code' => 422, 'etape' => $etape, 'cause' => $e->getMessage(), 'reference' => $reference,
        ], 422);
    }

    private static function auteur(Requete $req): string
    {
        $session = $req->getAttribute('ged.session');

        return is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';
    }

    private static function compte(Requete $req): ?int
    {
        $session = $req->getAttribute('ged.session');
        $compte = is_array($session) ? ($session['compte'] ?? null) : null;

        return is_int($compte) && $compte > 0 ? $compte : null;
    }

    private static function administrateur(Requete $req): bool
    {
        $session = $req->getAttribute('ged.session');

        return is_array($session) && in_array('*', array_values(array_map('strval', (array) ($session['permissions'] ?? []))), true);
    }
}
