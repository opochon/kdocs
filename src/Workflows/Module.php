<?php

declare(strict_types=1);

namespace Ged\Workflows;

use Ged\Base;
use Ged\Config;
use Ged\Ui\Maquette;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Slim\App;

/**
 * Routes du secteur Workflows. Chaque route déclare sa permission dans `Ged\Securite\DroitsRoutes` :
 * `workflows:lire` (liste, catalogue, éditeur), `workflow.gerer` (règles, circuits, gabarits, essai),
 * `workflow.executer` (événement), `workflow.transition` (transition à la main, dont le droit fin est
 * vérifié par le moteur).
 *
 * L'essai à blanc est un POST qui ne fait que rediriger vers l'éditeur : le calcul vit dans un GET, en lecture
 * seule. L'enregistrement du dessin est un POST JSON protégé par le CSRF de la garde centrale.
 */
final class Module
{
    /** @param App<\Psr\Container\ContainerInterface> $app */
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $pdo = Base::pdo($config);
        $horloge = new HorlogeSysteme();
        $moteur = new Moteur($pdo, new \Ged\Socle\DepotFichiers($config), $horloge);
        $regles = new Regles($pdo);
        $journal = new Journal($pdo);
        $fonds = Fonds::depuis($config, $pdo);
        $ecran = new Ecran($config, $pdo, $horloge);

        // --- La liste : circuits + règles (écran utilisateur, plus une console technique). ---

        // Créer une règle rapide (déclencheur + une condition + une action), sans passer par le dessin.
        $app->post('/workflows/regles', function (Requete $req, Reponse $rep) use ($regles, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $nom = trim((string) ($corps['nom'] ?? ''));
            $action = self::actionDepuisFormulaire($corps);
            if ($nom === '' || $action === null) {
                return self::erreur($vue, $rep, 422, 'POST /workflows/regles',
                    $nom === '' ? 'le nom de la règle est requis' : 'une action au moins est requise');
            }

            $conditions = [];
            $champ = trim((string) ($corps['condition_champ'] ?? ''));
            if ($champ !== '') {
                $conditions[] = ['champ' => $champ, 'operateur' => (string) ($corps['condition_operateur'] ?? 'egal'),
                    'valeur' => $corps['condition_valeur'] ?? ''];
            }

            $regles->creer([
                'nom' => $nom,
                'declencheur' => (string) ($corps['declencheur'] ?? 'document_depose'),
                'conditions' => $conditions,
                'actions' => [$action],
                'actif' => !empty($corps['actif']),
            ]);

            return self::redirection($rep, '/workflows');
        });

        // Activer / mettre en veille une règle (jamais de suppression).
        $app->post('/workflows/regles/{id:[0-9]+}/basculer', function (Requete $req, Reponse $rep, array $args) use ($regles): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $regles->basculer((int) $args['id'], (string) ($corps['actif'] ?? '0') === '1');

            return self::redirection($rep, '/workflows');
        });

        // Installer un gabarit d'un geste (idempotent).
        $app->post('/workflows/gabarits/{nom}', function (Requete $req, Reponse $rep, array $args) use ($pdo, $vue): Reponse {
            try {
                Gabarits::installer($pdo, (string) $args['nom']);
            } catch (ErreurWorkflow $e) {
                return self::erreur($vue, $rep, 422, 'POST /workflows/gabarits', $e->getMessage());
            }

            return self::redirection($rep, '/workflows');
        });

        // --- L'éditeur graphique. ---

        // Catalogue servi par une route déclarée : uniquement les composants que le moteur exécute.
        $app->get('/workflows/catalogue', function (Requete $req, Reponse $rep) use ($ecran): Reponse {
            return self::json($rep, $ecran->catalogue());
        });

        // Créer un circuit vide puis l'ouvrir dans l'éditeur.
        $app->post('/workflows/circuits', function (Requete $req, Reponse $rep) use ($pdo, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $nom = trim((string) ($corps['nom'] ?? ''));
            if ($nom === '') {
                return self::erreur($vue, $rep, 422, 'POST /workflows/circuits', 'le nom du circuit est requis');
            }
            try {
                $id = (new Editeur($pdo))->creerCircuit($nom);
            } catch (ErreurWorkflow $e) {
                return self::erreur($vue, $rep, 422, 'POST /workflows/circuits', $e->getMessage());
            }

            return self::redirection($rep, '/workflows/circuits/' . $id . '/editeur');
        });

        // Ouvrir l'éditeur d'un circuit (graphe réel, essai à blanc éventuel en lecture).
        $app->get('/workflows/circuits/{id:[0-9]+}/editeur', function (Requete $req, Reponse $rep, array $args) use ($ecran, $vue): Reponse {
            $donnees = $ecran->editeur((int) $args['id'], $req->getQueryParams());
            if ($donnees === null) {
                return $vue->rendre($rep, 'erreur.twig', [
                    'code' => 404, 'etape' => 'GET /workflows/circuits/' . (int) $args['id'] . '/editeur',
                    'cause' => 'ce circuit n’existe pas', 'reference' => substr(hash('sha256', 'workflows-editeur' . $args['id']), 0, 10),
                ], 404);
            }
            $donnees['ecran'] = 'workflows';
            $donnees['bilan'] = Maquette::bilan();
            $donnees['types'] = Maquette::TYPES;
            $donnees['csrf'] = (string) ($req->getCookieParams()['gedv2_csrf'] ?? '');

            return $vue->rendre($rep, 'ecrans/workflows-editeur.twig', $donnees);
        });

        // Enregistrer le dessin : le graphe se projette sur le circuit (états/transitions) et ses règles.
        $app->post('/workflows/circuits/{id:[0-9]+}', function (Requete $req, Reponse $rep, array $args) use ($pdo): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $graphe = $corps['graphe'] ?? null;
            if (is_string($graphe)) {
                $graphe = json_decode($graphe, true);
            }
            if (!is_array($graphe) || ($graphe['noeuds'] ?? []) === []) {
                return self::json($rep, ['ok' => false, 'cause' => 'le graphe est vide : ajoutez au moins un nœud'], 422);
            }

            try {
                $rapport = (new Editeur($pdo))->enregistrer((int) $args['id'], $graphe);
            } catch (ErreurWorkflow $e) {
                return self::json($rep, ['ok' => false, 'cause' => $e->getMessage()], 422);
            } catch (\Throwable $e) {
                return self::json($rep, ['ok' => false, 'cause' => 'enregistrement impossible : ' . $e->getMessage()], 422);
            }

            // Le nouveau jeton CSRF est rendu au client (la garde a consommé le précédent) : un second
            // enregistrement depuis l'éditeur reste possible sans recharger la page.
            $session = $req->getAttribute('ged.session');
            $csrf = is_array($session) ? (string) ($session['csrf'] ?? '') : '';

            return self::json($rep, ['ok' => true, 'csrf' => $csrf] + $rapport);
        });

        // --- Essai, événement, transition. ---

        // Essai à blanc : pure lecture. Avec un circuit, on revient à l'éditeur (chemin surligné).
        $app->post('/workflows/essai', function (Requete $req, Reponse $rep) use ($fonds, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $id = (int) ($corps['document_id'] ?? 0);
            if ($fonds->parId($id) === null) {
                return self::erreur($vue, $rep, 404, 'POST /workflows/essai', "document $id introuvable dans le fonds");
            }
            $suite = '?essai=' . $id . '&declencheur=' . rawurlencode((string) ($corps['declencheur'] ?? 'document_depose'));
            $circuit = (int) ($corps['circuit'] ?? 0);
            if ($circuit > 0) {
                return self::redirection($rep, '/workflows/circuits/' . $circuit . '/editeur' . $suite);
            }

            return self::redirection($rep, '/workflows' . $suite);
        });

        // Point d'accroche : le Socle signale un dépôt / une mise à jour. Le moteur ne lève jamais.
        $app->post('/workflows/evenements', function (Requete $req, Reponse $rep) use ($fonds, $moteur, $journal, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $id = (int) ($corps['document_id'] ?? 0);
            $fiche = $fonds->parId($id);
            if ($fiche === null) {
                return self::erreur($vue, $rep, 404, 'POST /workflows/evenements', "document $id introuvable dans le fonds");
            }
            $session = $req->getAttribute('ged.session');
            $auteur = is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'evenement';

            (new Ecouteur($moteur, $journal))->recevoir((string) ($corps['declencheur'] ?? 'document_depose'), $id, $fiche, $auteur);

            return self::redirection($rep, '/workflows');
        });

        // Transition à la main : le droit fin de la transition est vérifié par le moteur.
        $app->post('/workflows/transitions', function (Requete $req, Reponse $rep) use ($moteur, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $session = $req->getAttribute('ged.session');
            $permissions = is_array($session) ? array_values((array) ($session['permissions'] ?? [])) : [];
            $auteur = is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';

            try {
                $moteur->transitionner((int) ($corps['document_id'] ?? 0), (string) ($corps['vers'] ?? ''), $auteur, $permissions);
            } catch (ErreurWorkflow $e) {
                return self::erreur($vue, $rep, 403, 'POST /workflows/transitions', $e->getMessage());
            }

            return self::redirection($rep, '/workflows');
        });
    }

    /**
     * @param array<string, mixed> $corps
     * @return array<string, mixed>|null
     */
    private static function actionDepuisFormulaire(array $corps): ?array
    {
        $type = (string) ($corps['action_type'] ?? '');

        return match ($type) {
            'poser' => trim((string) ($corps['action_champ'] ?? '')) === '' ? null
                : ['type' => 'poser', 'champ' => (string) $corps['action_champ'], 'valeur' => $corps['action_valeur'] ?? ''],
            'etiqueter' => trim((string) ($corps['action_valeur'] ?? '')) === '' ? null
                : ['type' => 'etiqueter', 'etiquette' => (string) $corps['action_valeur']],
            'demander_validation', 'assigner' => trim((string) ($corps['action_circuit'] ?? '')) === '' ? null
                : ['type' => $type, 'circuit' => (string) $corps['action_circuit'],
                    'assigne' => (string) ($corps['action_role'] ?? ''),
                    'vers' => (string) ($corps['action_vers'] ?? '')],
            'notifier' => trim((string) ($corps['action_message'] ?? '')) === '' ? null
                : ['type' => 'notifier', 'destinataire' => (string) ($corps['action_destinataire'] ?? 'responsable'),
                    'message' => (string) $corps['action_message']],
            'appeler_jonction' => trim((string) ($corps['action_cible'] ?? '')) === '' ? null
                : ['type' => 'appeler_jonction', 'cible' => (string) $corps['action_cible'], 'charge' => []],
            default => null,
        };
    }

    private static function redirection(Reponse $rep, string $chemin): Reponse
    {
        return $rep->withHeader('Location', $chemin)->withStatus(303);
    }

    /** @param array<string, mixed> $donnees */
    private static function json(Reponse $rep, array $donnees, int $statut = 200): Reponse
    {
        $rep->getBody()->write((string) json_encode($donnees, JSON_UNESCAPED_UNICODE));

        return $rep->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($statut);
    }

    private static function erreur(Vue $vue, Reponse $rep, int $code, string $etape, string $cause): Reponse
    {
        return $vue->rendre($rep, 'erreur.twig', [
            'code' => $code,
            'etape' => $etape,
            'cause' => $cause,
            'reference' => substr(hash('sha256', 'workflows:' . $cause . microtime()), 0, 10),
        ], $code);
    }
}
