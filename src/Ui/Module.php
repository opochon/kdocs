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
    /** @param App<\Psr\Container\ContainerInterface> $app */
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
                $atraiter = ATraiter::depuisRacine($config->racine(), self::compte($req), self::administrateur($req));
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
                $bilan = ATraiter::depuisRacine($config->racine(), self::compte($req), self::administrateur($req))
                    ->validerPropositions($ids, $auteur);
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
                        $edition = new \Ged\Ingestion\EditionPieces($config, new \Ged\Socle\DepotFichiers($config), null,
                            self::compte($req), self::administrateur($req));
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

        // Collaboration V2-12d : les règles de session/CSRF sont déjà appliquées par la garde ; le
        // service garde la propriété de note, la lisibilité des deux documents et la traçabilité.
        $app->post('/documents/{id:[0-9]+}/notes', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $c=(array)($req->getParsedBody() ?: []); $a=self::auteur($req); $id=(int)$args['id']; $s=new \Ged\Socle\Collaboration($config);
            try { match((string)($c['action']??'ajouter')) { 'ajouter'=>$s->ajouterNote($id,$a,(string)($c['texte']??'')), 'modifier'=>$s->modifierNote((int)($c['note']??0),$a,(string)($c['texte']??'')), 'retirer'=>$s->retirerNote((int)($c['note']??0),$a), default=>throw new \RuntimeException('note : action inconnue') }; return $rep->withHeader('Location','/documents/'.$id.'?note_succes=1')->withStatus(303); } catch(\Throwable $e) { return self::erreur($vue,$rep,'POST /documents/{id}/notes',$e->getMessage()); }
        });
        $app->post('/documents/{id:[0-9]+}/liens', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $c=(array)($req->getParsedBody() ?: []);$id=(int)$args['id'];$s=new \Ged\Socle\Collaboration($config);
            try { if((string)($c['action']??'lier')==='retirer')$s->retirerLien((int)($c['lien']??0),self::auteur($req));else $s->lier($id,(int)($c['cible']??0),(string)($c['libelle']??''),self::auteur($req),self::compte($req)); return $rep->withHeader('Location','/documents/'.$id.'?lien_succes=1')->withStatus(303); } catch(\Throwable $e) { return self::erreur($vue,$rep,'POST /documents/{id}/liens',$e->getMessage()); }
        });
        // Famille M-Files nº 2 (lot ged-mfiles-relations-1) : relations TYPÉES de la fiche. Le service
        // résout les chemins depuis l'index, écrit le `.meta.json` (le dossier fait foi) et trace chaque
        // geste ; les règles de session/CSRF sont déjà appliquées par la garde centrale.
        $app->post('/documents/{id:[0-9]+}/relations', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $corps = (array) ($req->getParsedBody() ?: []);
            $id = (int) $args['id'];
            $compte = self::compte($req);
            $administrateur = self::administrateur($req);
            $depot = new \Ged\Socle\DepotFichiers($config);
            $relations = new \Ged\Socle\Relations($config);
            try {
                $action = (string) ($corps['action'] ?? 'lier');
                if ($action === 'retirer') {
                    $proprietaire = (string) ($corps['proprietaire'] ?? '');
                    self::exigerLecture($config, $compte, $administrateur, $proprietaire);
                    $relations->retirer($proprietaire, (int) ($corps['relation'] ?? 0), self::auteur($req));
                } elseif ($action === 'detacher') {
                    // Détacher (M-Files « demoting objects ») : le lien de composition est retiré, l'enfant
                    // devient indépendant. Chemins fournis par la fiche (propriétaire + cible).
                    $proprietaire = (string) ($corps['proprietaire'] ?? '');
                    $cible = (string) ($corps['cible'] ?? '');
                    self::exigerLecture($config, $compte, $administrateur, $proprietaire);
                    self::exigerLecture($config, $compte, $administrateur, $cible);
                    $relations->detacher($proprietaire, $cible, self::auteur($req));
                } else {
                    $source = self::cheminDocument($depot, $id) ?? throw new \RuntimeException("relation : document $id introuvable dans le fonds");
                    $cible = self::cheminDocument($depot, (int) ($corps['cible'] ?? 0)) ?? throw new \RuntimeException('relation : document cible introuvable dans le fonds');
                    self::exigerLecture($config, $compte, $administrateur, $source);
                    self::exigerLecture($config, $compte, $administrateur, $cible);
                    $relations->lier($source, $cible, (string) ($corps['type'] ?? ''), self::auteur($req));
                }

                return $rep->withHeader('Location', '/documents/' . $id . '?relation_succes=1')->withStatus(303);
            } catch (\Throwable $e) {
                return self::erreur($vue, $rep, 'POST /documents/{id}/relations', $e->getMessage());
            }
        });
        $app->post('/documents/{id:[0-9]+}/partages', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            return self::partageInterne($req, $rep, $args, $config, $vue);
        });
        $app->post('/documents/{id:[0-9]+}/partages/externe', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            return self::partageExterne($req, $rep, $args, $config, $vue);
        });
        $app->get('/partages-avec-moi', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $compte = self::compte($req);
            $partages = $compte === null ? [] : (new \Ged\Socle\Collaboration($config))->partagesAvecMoi($compte);
            return $vue->rendre($rep, 'ecrans/partages-avec-moi.twig', [
                'ecran' => 'partages', 'partages' => $partages,
            ]);
        });
        // Ces deux routes sont publiques seulement au sens de la garde : le jeton long, expirant et
        // révocable est vérifié ICI. Aucun autre chemin n'accepte un jeton.
        $app->get('/partages/{jeton:[A-Za-z0-9_-]+}', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            return self::pagePartageExterne($rep, $args, $config, $vue);
        });
        $app->get('/partages/{jeton:[A-Za-z0-9_-]+}/fichier', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            return self::fichierPartageExterne($rep, $args, $config, $vue);
        });
        $app->post('/application/reconstruction-index', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse { try{$j=(new \Ged\Socle\ReconstructionIndex($config))->lancer(self::auteur($req));return $rep->withHeader('Location','/application?reconstruction='.$j['id'])->withStatus(303);}catch(\Throwable $e){return self::erreur($vue,$rep,'POST /application/reconstruction-index',$e->getMessage());} });
        $app->post('/application/reconstruction-index/{id:[0-9]+}/tranche', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse { try{$j=(new \Ged\Socle\ReconstructionIndex($config))->avancer((int)$args['id']);return $rep->withHeader('Location','/application?reconstruction='.$j['id'])->withStatus(303);}catch(\Throwable $e){return self::erreur($vue,$rep,'POST /application/reconstruction-index/{id}/tranche',$e->getMessage());} });
        // Lot ged-pdfa-1 : rattrapage des copies d'archivage PDF/A du fonds déjà présent, borné et reprenable
        // comme la reconstruction. Lignes signalées hors secteur (Interface), commitées avec le lot.
        $app->post('/application/pdfa-rattrapage', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse { try{$j=(new \Ged\Ingestion\RattrapagePdfA($config))->lancer(self::auteur($req));return $rep->withHeader('Location','/application?pdfa='.$j['id'])->withStatus(303);}catch(\Throwable $e){return self::erreur($vue,$rep,'POST /application/pdfa-rattrapage',$e->getMessage());} });
        $app->post('/application/pdfa-rattrapage/{id:[0-9]+}/tranche', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse { try{$j=(new \Ged\Ingestion\RattrapagePdfA($config))->avancer((int)$args['id']);return $rep->withHeader('Location','/application?pdfa='.$j['id'])->withStatus(303);}catch(\Throwable $e){return self::erreur($vue,$rep,'POST /application/pdfa-rattrapage/{id}/tranche',$e->getMessage());} });

        // Lot ged-parite-final-1 : Référentiels de l'écran Application — émetteurs (Correspondent) et
        // emplacements de classement (StoragePath) se créent, se modifient et se retirent en veille ; la
        // configuration applicative (titre, langue) est inscriptible dans le fonds puis effaçable.
        $app->post('/application/emetteur', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $emetteurs = new \Ged\Socle\Emetteurs($config);
            try {
                match ((string) ($corps['action'] ?? 'ajouter')) {
                    'ajouter' => $emetteurs->ajouter((string) ($corps['nom'] ?? ''), (string) ($corps['algo'] ?? 'aucun'), (string) ($corps['motif'] ?? ''), (string) ($corps['insensible'] ?? '1') === '1'),
                    'regle' => $emetteurs->definirRegle((int) ($corps['id'] ?? 0), (string) ($corps['algo'] ?? 'aucun'), (string) ($corps['motif'] ?? ''), (string) ($corps['insensible'] ?? '1') === '1'),
                    'actif' => $emetteurs->definirActif((int) ($corps['id'] ?? 0), (string) ($corps['valeur'] ?? '1') === '1'),
                    default => throw new \RuntimeException('action inconnue (ajouter, regle, actif)'),
                };

                return $rep->withHeader('Location', '/application')->withStatus(303);
            } catch (\Throwable $e) {
                return self::erreur($vue, $rep, 'POST /application/emetteur', $e->getMessage());
            }
        });
        $app->post('/application/emplacement', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $emplacements = new \Ged\Socle\Emplacements($config);
            try {
                match ((string) ($corps['action'] ?? 'ajouter')) {
                    'ajouter' => $emplacements->ajouter((string) ($corps['nom'] ?? ''), (string) ($corps['chemin'] ?? '')),
                    'chemin' => $emplacements->definirChemin((int) ($corps['id'] ?? 0), (string) ($corps['chemin'] ?? '')),
                    'actif' => $emplacements->definirActif((int) ($corps['id'] ?? 0), (string) ($corps['valeur'] ?? '1') === '1'),
                    default => throw new \RuntimeException('action inconnue (ajouter, chemin, actif)'),
                };

                return $rep->withHeader('Location', '/application')->withStatus(303);
            } catch (\Throwable $e) {
                return self::erreur($vue, $rep, 'POST /application/emplacement', $e->getMessage());
            }
        });
        $app->post('/application/configuration', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $configuration = new \Ged\Socle\ConfigurationApp($config);
            try {
                if ((string) ($corps['action'] ?? 'modifier') === 'reinitialiser') {
                    $configuration->reinitialiser();
                } else {
                    $valeurs = [];
                    foreach (\Ged\Socle\ConfigurationApp::CLES as $cle) {
                        if (isset($corps[$cle]) && is_string($corps[$cle])) {
                            $valeurs[$cle] = $corps[$cle];
                        }
                    }
                    if ($valeurs === []) {
                        throw new \RuntimeException('aucune clé modifiable (titre, langue)');
                    }
                    $configuration->modifier($valeurs);
                }

                return $rep->withHeader('Location', '/application')->withStatus(303);
            } catch (\Throwable $e) {
                return self::erreur($vue, $rep, 'POST /application/configuration', $e->getMessage());
            }
        });

        // Lot ged-parite-ecrans-1 (Interface) : écrans rendant le service des écrans Paperless, à la
        // manière de GEDv2 (mêmes gabarits, même charte) — tableau de bord, journaux, corbeille, réglages
        // et accès par numéro d'archive. Les permissions sont déclarées dans `Ged\Securite\DroitsRoutes`
        // (lignes hors secteur, signalées dans docs/secteurs/interface.md).
        $app->get('/tableau-de-bord', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            return $vue->rendre($rep, 'ecrans/tableau-de-bord.twig', TableauDeBord::depuisRacine($config->racine())->donnees());
        });

        $app->get('/journaux', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            return $vue->rendre($rep, 'ecrans/journaux.twig', Journaux::depuisRacine($config->racine())->donnees($req->getQueryParams()));
        });

        $app->get('/corbeille', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $donnees = Corbeille::depuisRacine($config->racine())->donnees();
            $donnees['csrf'] = Ecrans::jetonCsrf($req);

            return $vue->rendre($rep, 'ecrans/corbeille.twig', $donnees);
        });
        $app->post('/corbeille/{id:[0-9]+}/restaurer', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            try {
                (new \Ged\Socle\DepotFichiers($config))->restaurer((int) $args['id']);

                return $rep->withHeader('Location', '/corbeille')->withStatus(303);
            } catch (\Throwable $e) {
                return self::erreur($vue, $rep, 'POST /corbeille/{id}/restaurer', $e->getMessage());
            }
        });

        $app->get('/reglages', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            return $vue->rendre($rep, 'ecrans/reglages.twig', self::donneesReglages($req, $config, ''));
        });
        $app->get('/reglages/{section:[a-z-]+}', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            return $vue->rendre($rep, 'ecrans/reglages.twig', self::donneesReglages($req, $config, (string) $args['section']));
        });
        $app->post('/reglages', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $section = (string) ($corps['section'] ?? '');
            $reglages = Reglages::depuisRacine($config->racine());
            try {
                $reglages->enregistrer(self::auteur($req), $section, $corps);

                return $rep->withHeader('Location', '/reglages/' . $reglages->sectionValide($section) . '?message=1')->withStatus(303);
            } catch (\Throwable $e) {
                return self::erreur($vue, $rep, 'POST /reglages', $e->getMessage());
            }
        });

        $app->get('/asn', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $numero = (int) ($req->getQueryParams()['numero'] ?? 0);
            if ($numero < 1) {
                return $vue->rendre($rep, 'ecrans/asn.twig', ['ecran' => 'asn', 'numero' => '', 'erreur' => null]);
            }
            $document = AccesArchive::depuisRacine($config->racine())->document($numero);
            if ($document === null) {
                return $vue->rendre($rep, 'ecrans/asn.twig', ['ecran' => 'asn', 'numero' => (string) $numero,
                    'erreur' => "aucun document ne porte le numéro d’archive $numero"]);
            }

            return $rep->withHeader('Location', '/documents/' . (int) $document['id'])->withStatus(303);
        });
        $app->get('/asn/{id:[0-9]+}', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $numero = (int) $args['id'];
            $document = AccesArchive::depuisRacine($config->racine())->document($numero);
            if ($document === null) {
                return $vue->rendre($rep, 'erreur.twig', ['code' => 404, 'etape' => 'GET /asn/' . $numero,
                    'cause' => "aucun document ne porte le numéro d’archive $numero", 'reference' => ''], 404);
            }

            return $rep->withHeader('Location', '/documents/' . (int) $document['id'])->withStatus(303);
        });
    }

    /** @return array<string, mixed> */
    private static function donneesReglages(Requete $req, Config $config, string $section): array
    {
        $session = $req->getAttribute('ged.session');
        $utilisateur = is_array($session) ? (string) ($session['utilisateur'] ?? '') : '';
        $permissions = is_array($session) ? array_values(array_map('strval', (array) ($session['permissions'] ?? []))) : [];
        $donnees = Reglages::depuisRacine($config->racine())->donnees($utilisateur, $section, $permissions, $req->getQueryParams());
        $donnees['csrf'] = Ecrans::jetonCsrf($req);

        return $donnees;
    }

    /** @param array<string, string> $args */
    private static function partageInterne(Requete $req, Reponse $rep, array $args, Config $config, Vue $vue): Reponse
    {
        $corps = (array) ($req->getParsedBody() ?: []);
        $id = (int) $args['id'];
        try {
            $service = new \Ged\Socle\Collaboration($config);
            if (($corps['action'] ?? '') === 'interne') {
                $service->partagerInterne($id, (string) ($corps['cible_type'] ?? ''), (int) ($corps['cible'] ?? 0), self::auteur($req));
            } elseif (($corps['action'] ?? '') === 'revoquer') {
                $service->revoquerPartageInterne((int) ($corps['partage'] ?? 0), self::auteur($req));
            } else {
                throw new \RuntimeException('partage interne : action inconnue');
            }
            return $rep->withHeader('Location', '/documents/' . $id . '?partage_succes=1')->withStatus(303);
        } catch (\Throwable $e) {
            return self::erreur($vue, $rep, 'POST /documents/{id}/partages', $e->getMessage());
        }
    }

    /** @param array<string, string> $args */
    private static function partageExterne(Requete $req, Reponse $rep, array $args, Config $config, Vue $vue): Reponse
    {
        $corps = (array) ($req->getParsedBody() ?: []);
        $id = (int) $args['id'];
        try {
            $service = new \Ged\Socle\Collaboration($config);
            if (($corps['action'] ?? '') === 'revoquer') {
                $service->revoquerPartageExterne((int) ($corps['partage'] ?? 0), self::auteur($req));
            } else {
                $partage = $service->partagerExterne($id, self::auteur($req), (string) ($corps['expiration'] ?? ''));
                (new \Ged\Socle\JetonPartageSession($config))->poser((string) ($req->getCookieParams()['gedv2_session'] ?? ''), $partage['jeton']);
            }
            return $rep->withHeader('Location', '/documents/' . $id)->withStatus(303);
        } catch (\Throwable $e) {
            return self::erreur($vue, $rep, 'POST /documents/{id}/partages/externe', $e->getMessage());
        }
    }

    /** @param array<string, string> $args */
    private static function pagePartageExterne(Reponse $rep, array $args, Config $config, Vue $vue): Reponse
    {
        $service = new \Ged\Socle\Collaboration($config);
        $partage = $service->lienExterneValide((string) $args['jeton']);
        if ($partage === null) return self::erreur($vue, $rep, 'GET /partages/{jeton}', 'lien externe invalide, expiré ou révoqué', 403);
        $document = $service->cheminDocument($partage['document']);
        if ($document === null) return self::erreur($vue, $rep, 'GET /partages/{jeton}', 'document indisponible', 404);
        return $vue->rendre($rep, 'ecrans/partage-externe.twig', ['ecran' => 'partage-externe', 'titre' => $document['titre'], 'jeton' => (string) $args['jeton']]);
    }

    /** @param array<string, string> $args */
    private static function fichierPartageExterne(Reponse $rep, array $args, Config $config, Vue $vue): Reponse
    {
        $service = new \Ged\Socle\Collaboration($config);
        $partage = $service->lienExterneValide((string) $args['jeton']);
        if ($partage === null) return self::erreur($vue, $rep, 'GET /partages/{jeton}/fichier', 'lien externe invalide, expiré ou révoqué', 403);
        $document = $service->cheminDocument($partage['document']);
        $racine = realpath($config->chemin('STORAGE_ROOT', 'storage/documents'));
        $fichier = $document === null ? false : realpath($racine . '/' . $document['chemin_relatif']);
        if ($racine === false || $fichier === false || !str_starts_with(str_replace('\\', '/', $fichier), str_replace('\\', '/', $racine) . '/')) return self::erreur($vue, $rep, 'GET /partages/{jeton}/fichier', 'document indisponible', 404);
        $rep->getBody()->write((string) file_get_contents($fichier));
        return $rep->withHeader('Content-Type', (string) $document['mime'])->withHeader('Content-Disposition', 'inline; filename="' . rawurlencode((string) $document['nom']) . '"');
    }

    private static function auteur(Requete $req): string { $s=$req->getAttribute('ged.session'); return is_array($s)?(string)($s['utilisateur']??'inconnu'):'inconnu'; }
    /** Chemin du fonds d'un document de l'index, ou null : les relations s'adressent par le dossier. */
    private static function cheminDocument(\Ged\Socle\DepotFichiers $depot, int $id): ?string { if ($id < 1) { return null; } $fiche = $depot->fiche($id); return $fiche === null ? null : (string) $fiche["chemin_relatif"]; }
    /** Une relation n'est jamais posée, retirée ni détachée sur un document hors des droits de l'utilisateur. */
    private static function exigerLecture(Config $config, ?int $compte, bool $administrateur, string $chemin): void { if ($compte === null || $administrateur || $chemin === '' || !class_exists(\Ged\Securite\DroitsDossiers::class)) { return; } $droits = new \Ged\Securite\DroitsDossiers(\Ged\Base::pdo($config)); $dossier = $droits->dossierPourChemin($chemin); if ($dossier === null || !$droits->autorise($compte, $dossier, 'lire')) { throw new \RuntimeException('relation : document hors de vos droits'); } }
    private static function compte(Requete $req): ?int { $s=$req->getAttribute('ged.session');$c=is_array($s)?($s['compte']??null):null;return is_int($c)&&$c>0?$c:null; }
    private static function administrateur(Requete $req): bool { $s=$req->getAttribute('ged.session');return is_array($s)&&in_array('*', array_values(array_map('strval',(array)($s['permissions']??[]))),true); }
    private static function erreur(Vue $vue, Reponse $rep, string $etape, string $cause, int $code=422): Reponse { return $vue->rendre($rep,'erreur.twig',['code'=>$code,'etape'=>$etape,'cause'=>$cause,'reference'=>''],$code); }
}
