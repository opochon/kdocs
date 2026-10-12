<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use RuntimeException;
use Slim\App;

/**
 * Routes du secteur Socle pour le lot V2-04 : la bibliothèque branchée sur les vraies données (fonds,
 * vues, champs personnalisés) et l'écran métadonnées de l'administrateur (nomenclature, champs).
 * Chaque route déclare sa permission dans `Ged\Securite\DroitsRoutes`.
 *
 * `GET /bibliotheque` est enregistré ICI, avant l'écran maître d'Interface : Slim sert la première
 * route enregistée, la bibliothèque réelle prend donc la place de la maquette sans toucher au Kernel.
 */
final class Module
{
    /** @param App<\Psr\Container\ContainerInterface> $app */
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        // Le schéma est exclusivement posé par tools/installer.php, les outils CLI et les amorces
        // de test. Le Kernel est créé à chaque requête : aucune migration ne doit donc vivre ici.

        // La bibliothèque elle-même (GET /bibliotheque) vit dans Ged\Ui\Ecrans : FastRoute refuse deux
        // routes identiques, et le Kernel enregistre déjà la sienne. L'écran maître délègue au Socle
        // par la même couture que /recherche et /workflows (class_exists) — voir Ecrans::bibliotheque.

        // Enregistrer la vue courante : les filtres du formulaire deviennent une vue nommée.
        $app->post('/bibliotheque/vue', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $nom = trim((string) ($corps['nom'] ?? ''));
            try {
                $id = (new Vues($config))->enregistrer($nom, $corps, self::auteur($req));

                return $rep->withHeader('Location', "/bibliotheque?vue=$id")->withStatus(303);
            } catch (RuntimeException $e) {
                return self::nommer($vue, $rep, 'POST /bibliotheque/vue', $e->getMessage());
            }
        });

        // Modification en masse (lot ged-parite-masse) : la sélection vient de l'écran (cases cochées ou
        // « tout le résultat filtré »). Chaque document passe ensuite par le service d'édition UNITAIRE ;
        // l'ACL est vérifiée par document et le travail s'exécute par paquets courts et reprenables. Le
        // premier paquet part sans attendre un second clic ; le reste avance à la demande, par tranche.
        $app->post('/bibliotheque/masse', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $session = $req->getAttribute('ged.session');
            $compte = is_array($session) && is_int($session['compte'] ?? null) ? $session['compte'] : null;
            $permissions = is_array($session) ? array_values(array_map('strval', (array) ($session['permissions'] ?? []))) : [];
            $administrateur = in_array('*', $permissions, true);
            $action = (string) ($corps['action'] ?? '');
            $valeur = match ($action) {
                'type' => $corps['masse_type'] ?? null,
                'emetteur' => $corps['masse_emetteur'] ?? null,
                'dossier' => $corps['masse_dossier'] ?? null,
                'etiquettes_ajouter', 'etiquettes_retirer' => $corps['masse_etiquettes'] ?? null,
                default => null,
            };
            try {
                $ecran = new EcranBibliotheque($config, $compte, $administrateur, $permissions);
                $ids = (string) ($corps['tout_resultat'] ?? '') === '1'
                    ? $ecran->idsFiltres($corps)
                    : (array) ($corps['documents'] ?? []);
                $service = new ModificationMasse($config, $compte, $administrateur);
                $job = $service->lancer($ids, ['action' => $action, 'valeur' => $valeur], self::auteur($req));
                $etat = $service->avancer((int) $job['id'], ModificationMasse::LOT_DEFAUT);

                return $rep->withHeader('Location', '/bibliotheque?masse=' . (int) $etat['id'])->withStatus(303);
            } catch (RuntimeException $e) {
                return self::nommer($vue, $rep, 'POST /bibliotheque/masse', $e->getMessage());
            }
        });

        // Tranche suivante d'une modification en masse : l'état vit dans le fonds, la tâche reste donc
        // reprenable même servie par un autre processus. Aucune tranche ne dépasse « LOT_MAX ».
        $app->post('/bibliotheque/masse/{id:[0-9]+}/tranche', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            try {
                $etat = (new ModificationMasse($config))->avancer((int) $args['id'], ModificationMasse::LOT_DEFAUT);

                return $rep->withHeader('Location', '/bibliotheque?masse=' . (int) $etat['id'])->withStatus(303);
            } catch (RuntimeException $e) {
                return self::nommer($vue, $rep, 'POST /bibliotheque/masse/{id}/tranche', $e->getMessage());
            }
        });

        // Les vues de Recherche vivent dans le même `.ged/vues.json` et se voient ensuite dans le rail Bibliothèque.
        $app->post('/recherche/vue', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            try {
                $id = (new Vues($config))->enregistrerRecherche(trim((string) ($corps['nom'] ?? '')), $corps, self::auteur($req));
                return $rep->withHeader('Location', "/bibliotheque?vue=$id")->withStatus(303);
            } catch (RuntimeException $e) {
                return self::nommer($vue, $rep, 'POST /recherche/vue', $e->getMessage());
            }
        });

        // Poser les valeurs des champs personnalisés d'un document : écriture dans le `.meta.json`
        // (le dossier fait foi), report immédiat dans l'index. Jamais un déplacement de fichier.
        $app->post('/documents/champ', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $id = (int) ($corps['doc'] ?? 0);
            $doc = (new Fonds($config))->parId($id, Base::pdo($config));
            if ($doc === null) {
                return self::nommer($vue, $rep, 'POST /documents/champ', "document $id introuvable dans le fonds");
            }
            $retour = '/bibliotheque?doc=' . $id;

            $champs = new Champs($config);
            $poses = isset($corps['champ']) && is_array($corps['champ']) ? $corps['champ'] : [];
            try {
                // La valeur vide efface le champ : le formulaire envoie TOUTES les clés du type.
                foreach ($poses as $cle => $valeur) {
                    $champs->poserValeur((string) $doc['chemin'], (string) $cle, (string) $valeur);
                }

                return $rep->withHeader('Location', $retour)->withStatus(303);
            } catch (RuntimeException $e) {
                return self::nommer($vue, $rep, 'POST /documents/champ', $e->getMessage());
            }
        });

        // Écran métadonnées de l'administrateur : la nomenclature des types et les champs par type.
        $app->get('/metadonnees', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $donnees = (new EcranMetadonnees($config))->donnees($req->getQueryParams(), self::csrf($req));

            return $vue->rendre($rep, 'ecrans/metadonnees.twig', $donnees);
        });

        // Modifier la nomenclature : ajouter un type, le renommer, l'activer ou le désactiver.
        $app->post('/metadonnees/type', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $nomenclature = new Nomenclature($config);
            try {
                match ((string) ($corps['action'] ?? '')) {
                    'graine' => $nomenclature->assurer(),
                    'ajouter' => $nomenclature->ajouter((string) ($corps['nom'] ?? '')),
                    'renommer' => $nomenclature->renommer((int) ($corps['id'] ?? 0), (string) ($corps['nom'] ?? '')),
                    'activer' => $nomenclature->definirActif((int) ($corps['id'] ?? 0), true),
                    'desactiver' => $nomenclature->definirActif((int) ($corps['id'] ?? 0), false),
                    default => throw new RuntimeException('action inconnue (graine, ajouter, renommer, activer, desactiver)'),
                };

                return $rep->withHeader('Location', '/metadonnees')->withStatus(303);
            } catch (RuntimeException $e) {
                return self::nommer($vue, $rep, 'POST /metadonnees/type', $e->getMessage());
            }
        });

        // Créer un champ personnalisé typé sur un type de la nomenclature, ou le mettre en veille
        // (jamais le supprimer : ses valeurs restent dans les `.meta.json`).
        $app->post('/metadonnees/champ', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $options = array_values(array_filter(array_map('trim', explode("\n", (string) ($corps['options'] ?? ''))),
                static fn (string $o) => $o !== ''));
            $retour = '/metadonnees?type=' . (int) ($corps['type'] ?? 0);
            try {
                match ((string) ($corps['action'] ?? 'ajouter')) {
                    'ajouter' => (new Champs($config))->ajouter((int) ($corps['type'] ?? 0), (string) ($corps['nom'] ?? ''),
                        (string) ($corps['nature'] ?? ''), $options),
                    'desactiver' => (new Champs($config))->definirActif((int) ($corps['id'] ?? 0), false),
                    'reactiver' => (new Champs($config))->definirActif((int) ($corps['id'] ?? 0), true),
                    default => throw new RuntimeException('action inconnue (ajouter, desactiver, reactiver)'),
                };

                return $rep->withHeader('Location', $retour)->withStatus(303);
            } catch (RuntimeException $e) {
                return self::nommer($vue, $rep, 'POST /metadonnees/champ', $e->getMessage());
            }
        });

        // Étiquettes de classement (modèle Paperless Tag) : ajouter, recolorer, renommer, poser la boîte
        // de réception. Une étiquette n'est jamais supprimée (le dossier fait foi).
        $app->post('/metadonnees/etiquette', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $etiquettes = new Etiquettes($config);
            try {
                match ((string) ($corps['action'] ?? 'ajouter')) {
                    'ajouter' => $etiquettes->ajouter((string) ($corps['nom'] ?? ''), (string) ($corps['couleur'] ?? Etiquettes::COULEUR_DEFAUT),
                        (string) ($corps['boite_reception'] ?? '') === '1',
                        (int) ($corps['parent'] ?? 0) > 0 ? (int) $corps['parent'] : null),
                    'couleur' => $etiquettes->definirCouleur((int) ($corps['id'] ?? 0), (string) ($corps['couleur'] ?? '')),
                    'renommer' => $etiquettes->renommer((int) ($corps['id'] ?? 0), (string) ($corps['nom'] ?? '')),
                    'boite' => $etiquettes->definirBoiteReception((int) ($corps['id'] ?? 0), (string) ($corps['valeur'] ?? '1') === '1'),
                    'parent' => $etiquettes->definirParent((int) ($corps['id'] ?? 0),
                        (int) ($corps['parent'] ?? 0) > 0 ? (int) $corps['parent'] : null),
                    'actif' => $etiquettes->definirActif((int) ($corps['id'] ?? 0), (string) ($corps['valeur'] ?? '1') === '1'),
                    default => throw new RuntimeException('action inconnue (ajouter, couleur, renommer, boite, parent, actif)'),
                };

                return $rep->withHeader('Location', '/metadonnees')->withStatus(303);
            } catch (RuntimeException $e) {
                return self::nommer($vue, $rep, 'POST /metadonnees/etiquette', $e->getMessage());
            }
        });

        // Suivi des traitements (modèle PaperlessTask) : consulter l'avancement et acquitter une tâche.
        $app->get('/taches', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            return $vue->rendre($rep, 'ecrans/taches.twig', (new EcranTaches($config))->donnees(self::csrf($req)));
        });
        $app->post('/taches/{id:[0-9]+}/accuser', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            try {
                (new Taches($config))->accuser((int) $args['id']);

                return $rep->withHeader('Location', '/taches')->withStatus(303);
            } catch (RuntimeException $e) {
                return self::nommer($vue, $rep, 'POST /taches/{id}/accuser', $e->getMessage());
            }
        });

        // Vues enregistrées (modèle SavedView) : créer, nommer, choisir champs, mode, tri, tableau de bord.
        $app->get('/vues', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            return $vue->rendre($rep, 'ecrans/vues.twig', (new EcranVues($config))->donnees(self::csrf($req)));
        });
        $app->post('/vues', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            try {
                (new Vues($config))->enregistrer((string) ($corps['nom'] ?? ''), $corps, self::auteur($req));

                return $rep->withHeader('Location', '/vues')->withStatus(303);
            } catch (RuntimeException $e) {
                return self::nommer($vue, $rep, 'POST /vues', $e->getMessage());
            }
        });
        $app->post('/vues/{id:[0-9]+}', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            try {
                (new Vues($config))->modifierReglages((int) $args['id'], $corps, self::auteur($req));

                return $rep->withHeader('Location', '/vues')->withStatus(303);
            } catch (RuntimeException $e) {
                return self::nommer($vue, $rep, 'POST /vues/{id}', $e->getMessage());
            }
        });
    }

    /** Le jeton CSRF de la double soumission : le cookie posé par la garde, joint au formulaire. */
    private static function csrf(Requete $req): string
    {
        return (string) ($req->getCookieParams()['gedv2_csrf'] ?? '');
    }

    /** L'auteur connecté, pour les vues enregistrées (attribut posé par la garde centrale). */
    private static function auteur(Requete $req): string
    {
        $session = $req->getAttribute('ged.session');

        return is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'console';
    }

    /**
     * Un refus nommé : l'écran d'erreur porte l'étape et la cause. Une erreur de saisie ne sera
     * jamais un silence ni un écran blanc.
     */
    private static function nommer(Vue $vue, Reponse $rep, string $etape, string $cause): Reponse
    {
        return $vue->rendre($rep, 'erreur.twig', ['code' => 422, 'etape' => $etape, 'cause' => $cause,
            'reference' => ''], 422);
    }
}
