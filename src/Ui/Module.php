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
        // Nouvelle version : le fichier est remplacé par le Socle, qui archive systématiquement chaque
        // contenu dans `.versions/` et refuse lui-même un document scellé. La garde centrale a déjà
        // vérifié permission et CSRF avant d'arriver ici.
        $app->post('/documents/{id:[0-9]+}/versions', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $id = (int) ($args['id'] ?? 0);
            $session = $req->getAttribute('ged.session');
            $auteur = is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';
            $fichier = $req->getUploadedFiles()['fichier'] ?? null;
            $temporaire = null;
            try {
                if (!$fichier instanceof \Psr\Http\Message\UploadedFileInterface || $fichier->getError() !== UPLOAD_ERR_OK) {
                    throw new \RuntimeException('nouvelle version : choisissez un fichier à téléverser');
                }
                $temporaire = tempnam(sys_get_temp_dir(), 'gedv2-version-');
                if ($temporaire === false) {
                    throw new \RuntimeException('nouvelle version : espace temporaire indisponible');
                }
                $fichier->moveTo($temporaire);
                $version = (new \Ged\Socle\DepotFichiers($config))->remplacerContenu($id, $temporaire, $auteur);

                return $rep->withHeader('Location', '/documents/' . $id . '?version_succes=' . $version)->withStatus(303);
            } catch (\Ged\Archivage\ErreurScellement $e) {
                return $vue->rendre($rep, 'erreur.twig', [
                    'code' => 403, 'etape' => 'POST /documents/{id}/versions', 'cause' => $e->getMessage(),
                    'reference' => substr(hash('sha256', 'version:' . $e->getMessage() . microtime()), 0, 10),
                ], 403);
            } catch (\Throwable $e) {
                return $rep->withHeader('Location', '/documents/' . $id . '?version_erreur=' . rawurlencode($e->getMessage()))->withStatus(303);
            } finally {
                if (is_string($temporaire) && is_file($temporaire)) {
                    @unlink($temporaire);
                }
            }
        });

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

        // Dépôt par photo (V2-10a) : l'écran mobile « Déposer ». Même chaîne d'ingestion que le dépôt de
        // bureau (SourceApi + Travaux), même droit que « À traiter » ; le retour est un PRG qui nomme
        // l'état « reçu, en cours de lecture ». Aucune donnée n'est rangée ici : le Pilote s'en charge.
        $app->get('/deposer', function (Requete $req, Reponse $rep) use ($vue): Reponse {
            $parametres = $req->getQueryParams();

            return $vue->rendre($rep, 'ecrans/deposer.twig', [
                'ecran' => 'deposer',
                'csrf' => Ecrans::jetonCsrf($req),
                'recus' => max(0, (int) ($parametres['recu'] ?? 0)),
                'erreur' => isset($parametres['erreur']) ? (string) $parametres['erreur'] : '',
            ]);
        });

        $app->post('/deposer', function (Requete $req, Reponse $rep) use ($config): Reponse {
            $session = $req->getAttribute('ged.session');
            $auteur = is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';

            $bruts = $req->getUploadedFiles()['fichiers'] ?? [];
            $fichiers = [];
            foreach (is_array($bruts) ? $bruts : [$bruts] as $fichier) {
                if ($fichier instanceof \Psr\Http\Message\UploadedFileInterface) {
                    $fichiers[] = $fichier;
                }
            }
            $fichiers = array_slice($fichiers, 0, 20);   // borne : un envoi reste court et repris par la file

            try {
                if ($fichiers === []) {
                    throw new \RuntimeException('aucun fichier reçu : joignez une photo ou un PDF');
                }
                $resultats = Depot::depuisRacine($config->racine())->recevoir($fichiers, $auteur);
            } catch (\Throwable $e) {
                return $rep->withHeader('Location', '/deposer?erreur=' . rawurlencode($e->getMessage()))->withStatus(303);
            }

            $recus = count(array_filter($resultats, static fn (array $r): bool => $r['travail'] !== null));
            $erreurs = array_values(array_filter(array_map(
                static fn (array $r): string => $r['erreur'] ?? '',
                $resultats,
            ), static fn (string $e): bool => $e !== ''));

            $cible = '/deposer?recu=' . $recus;
            if ($erreurs !== []) {
                $cible .= '&erreur=' . rawurlencode(implode(' ; ', $erreurs));
            }

            return $rep->withHeader('Location', $cible)->withStatus(303);
        });
    }
}
