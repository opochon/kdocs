<?php

declare(strict_types=1);

namespace Ged\Archivage;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Socle\DepotFichiers;
use Ged\Ui\Ecrans;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Slim\App;
use Throwable;

/** Routes de l'archivage : mutations courtes, liste du fonds et vérification reprise. */
final class Module
{
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $app->get('/archives', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            try {
                $donnees = (new EcranArchives($config))->donnees($req->getQueryParams());
                $donnees = self::filtrerParDroits($donnees, $req, $config);
                $idTache = (string) ($req->getQueryParams()['verification'] ?? '');
                if ($idTache !== '') {
                    $tache = (new TacheVerification($config->chemin('STORAGE_ROOT', 'storage/documents')))->reprendre($idTache);
                    $donnees['verification'] = $tache;
                }
            } catch (Throwable $e) {
                $donnees = ['documents' => [], 'a_echeance' => [], 'types' => [],
                    'filtres' => ['type' => '', 'echeance' => 'toutes'],
                    'erreur' => 'Archives : ' . $e->getMessage()];
            }
            $donnees += ['verification' => null, 'erreur' => null, 'csrf' => Ecrans::jetonCsrf($req), 'ecran' => 'archives'];

            return $vue->rendre($rep, 'ecrans/archives.twig', $donnees);
        });

        $app->post('/documents/{id:[0-9]+}/sceller', function (Requete $req, Reponse $rep, array $args) use ($config): Reponse {
            $id = (int) ($args['id'] ?? 0);
            $session = $req->getAttribute('ged.session');
            $auteur = is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';
            try {
                $fiche = (new DepotFichiers($config))->fiche($id);
                if ($fiche === null || (string) ($fiche['statut'] ?? '') !== 'valide') {
                    throw new \RuntimeException('seuls les documents validés peuvent être scellés');
                }
                Scelleur::depuisConfig($config)->sceller($id, $auteur);

                return $rep->withHeader('Location', '/documents/' . $id . '?archivage_succes=1')->withStatus(303);
            } catch (Throwable $e) {
                return $rep->withHeader('Location', '/documents/' . $id . '?archivage_erreur=' . rawurlencode($e->getMessage()))->withStatus(303);
            }
        });

        $app->post('/archives/verifier', function (Requete $req, Reponse $rep) use ($config): Reponse {
            try {
                $taches = new TacheVerification($config->chemin('STORAGE_ROOT', 'storage/documents'));
                $tache = $taches->demarrer();
                $tache = $taches->reprendre((string) $tache['id']);

                return $rep->withHeader('Location', '/archives?verification=' . rawurlencode((string) $tache['id']))->withStatus(303);
            } catch (Throwable $e) {
                return $rep->withHeader('Location', '/archives?erreur=' . rawurlencode($e->getMessage()))->withStatus(303);
            }
        });
    }

    /** @param array<string,mixed> $donnees @return array<string,mixed> */
    private static function filtrerParDroits(array $donnees, Requete $req, Config $config): array
    {
        $session = $req->getAttribute('ged.session');
        if (!is_array($session) || !is_int($session['compte'] ?? null) || in_array('*', $session['permissions'] ?? [], true)) {
            return $donnees;
        }
        try {
            $droits = new DroitsDossiers(Base::pdo($config));
            $compte = $session['compte'];
            $autorise = static function (array $document) use ($droits, $compte): bool {
                $dossier = $droits->dossierPourDocument((int) $document['document_id']);

                return $dossier !== null && $droits->autorise($compte, $dossier, 'lire');
            };
            $donnees['documents'] = array_values(array_filter($donnees['documents'], $autorise));
            $donnees['a_echeance'] = array_values(array_filter($donnees['a_echeance'], $autorise));
        } catch (Throwable) {
            $donnees['documents'] = [];
            $donnees['a_echeance'] = [];
        }

        return $donnees;
    }
}
