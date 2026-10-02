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

        // Restaurer crée une NOUVELLE version depuis vN ; le Socle garde tout l'historique et refuse un scellement.
        $app->post('/documents/{id:[0-9]+}/versions/{numero:[0-9]+}/restaurer', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $id = (int) ($args['id'] ?? 0);
            $session = $req->getAttribute('ged.session');
            $auteur = is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';
            try {
                $nouvelle = (new \Ged\Socle\DepotFichiers($config))->restaurerVersion($id, (int) ($args['numero'] ?? 0), $auteur);
                return $rep->withHeader('Location', '/documents/' . $id . '?version_succes=' . $nouvelle)->withStatus(303);
            } catch (\Ged\Archivage\ErreurScellement $e) {
                return $vue->rendre($rep, 'erreur.twig', ['code' => 403, 'etape' => 'POST /documents/{id}/versions/{n}/restaurer',
                    'cause' => $e->getMessage(), 'reference' => substr(hash('sha256', 'restauration:' . microtime()), 0, 10)], 403);
            } catch (Throwable $e) {
                return $rep->withHeader('Location', '/documents/' . $id . '?version_erreur=' . rawurlencode($e->getMessage()))->withStatus(303);
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

        $app->post('/a-traiter/valider-lot', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $ids = array_values(array_filter(array_map('intval', (array) ($corps['documents'] ?? [])), static fn (int $id): bool => $id > 0));
            $session = $req->getAttribute('ged.session');
            $auteur = is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';
            try {
                $bilan = ATraiter::depuisRacine($config->racine())->validerPropositions($ids, $auteur);
            } catch (Throwable $e) {
                return $vue->rendre($rep, 'erreur.twig', ['code' => 422, 'etape' => 'POST /a-traiter/valider-lot',
                    'cause' => $e->getMessage(), 'reference' => substr(hash('sha256', 'lot:' . microtime()), 0, 10)], 422);
            }
            return $rep->withHeader('Location', '/a-traiter?lot_valide=' . count($bilan['valides'])
                . '&lot_refus=' . rawurlencode(json_encode($bilan['refuses'], JSON_UNESCAPED_UNICODE) ?: '{}'))->withStatus(303);
        });

        // V2-12a : chaque route de page est une mutation CSRF + `documents:traiter` (la garde centrale
        // porte ces deux contrôles avant ce code). Le service refuse encore une pièce validée ou scellée :
        // la route ne suffit jamais à contourner cette défense métier.
        foreach (['tourner', 'scinder', 'fusionner', 'retirer', 'annuler'] as $action) {
            $app->post('/a-traiter/{id:[0-9]+}/pages/' . $action,
                function (Requete $req, Reponse $rep, array $args) use ($config, $vue, $action): Reponse {
                    $id = (int) ($args['id'] ?? 0);
                    $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
                    $session = $req->getAttribute('ged.session');
                    $auteur = is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';
                    try {
                        $edition = new \Ged\Ingestion\EditionPieces($config, new \Ged\Socle\DepotFichiers($config));
                        match ($action) {
                            'tourner' => $edition->tourner($id, (int) ($corps['page'] ?? 0), (int) ($corps['degres'] ?? 0), $auteur),
                            'scinder' => $edition->scinder($id, (int) ($corps['page'] ?? 0), $auteur),
                            'fusionner' => $edition->fusionner($id, (int) ($corps['avec'] ?? 0), $auteur),
                            'retirer' => $edition->retirer($id, (int) ($corps['page'] ?? 0), $auteur),
                            'annuler' => $edition->annuler($id, $auteur),
                        };
                    } catch (Throwable $e) {
                        $reference = substr(hash('sha256', 'edition:' . $action . ':' . $e->getMessage() . microtime()), 0, 10);
                        error_log(sprintf('[GEDv2 edition %s] POST /a-traiter/%d/pages/%s : %s (%s:%d)', $reference, $id,
                            $action, $e->getMessage(), $e->getFile(), $e->getLine()));
                        return $vue->rendre($rep, 'erreur.twig', [
                            'code' => 422, 'etape' => 'POST /a-traiter/{id}/pages/' . $action,
                            'cause' => \Ged\Ingestion\EditeurPdf::messageUtilisateur($e),
                            'reference' => $reference,
                        ], 422);
                    }

                    return $rep->withHeader('Location', '/a-traiter?doc=' . $id . '&edition_succes=' . $action)->withStatus(303);
                });
        }

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
