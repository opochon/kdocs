<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Courrier\Admin;
use Ged\Ingestion\Courrier\Compte;
use Ged\Ingestion\Courrier\ErreurCourrier;
use Ged\Ingestion\Courrier\Fabrique;
use Ged\Ingestion\Courrier\Regle;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Slim\App;

/**
 * Routes du secteur Ingestion : l'administration du modèle courrier (comptes, règles, journal des courriels
 * traités), réservée aux administrateurs (`application:administrer`). Chaque route déclare sa permission dans
 * `Ged\Securite\DroitsRoutes`. Le test de connexion branche le PORT injectable : faux en mémoire en test,
 * IMAP réel sinon.
 */
final class Module
{
    /** @param App<\Psr\Container\ContainerInterface> $app */
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $pdo = Base::pdo($config);
        $admin = new Admin($config);

        $app->get('/courrier', function (Requete $req, Reponse $rep) use ($admin, $vue): Reponse {
            $donnees = $admin->liste();
            $donnees['ecran'] = 'application';
            $donnees['csrf'] = self::csrf($req);

            return $vue->rendre($rep, 'ecrans/courrier.twig', $donnees);
        });

        // --- Comptes ---

        $app->post('/courrier/comptes', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = self::corps($req);
            try {
                Fabrique::comptes($config)->creer(self::compteDepuisFormulaire($corps));
            } catch (ErreurCourrier $e) {
                return self::erreur($vue, $rep, 422, 'POST /courrier/comptes', $e->getMessage());
            }

            return self::redirection($rep, '/courrier');
        });

        $app->get('/courrier/comptes/{id:[0-9]+}', function (Requete $req, Reponse $rep, array $args) use ($admin, $vue): Reponse {
            $donnees = $admin->compte((int) $args['id']);
            if ($donnees === null) {
                return self::erreur($vue, $rep, 404, 'GET /courrier/comptes/' . (int) $args['id'], 'ce compte n’existe pas');
            }
            $donnees['ecran'] = 'application';
            $donnees['csrf'] = self::csrf($req);

            return $vue->rendre($rep, 'ecrans/courrier-compte.twig', $donnees);
        });

        $app->post('/courrier/comptes/{id:[0-9]+}', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $corps = self::corps($req);
            try {
                Fabrique::comptes($config)->mettreAJour((int) $args['id'], self::compteDepuisFormulaire($corps));
            } catch (ErreurCourrier $e) {
                return self::erreur($vue, $rep, 422, 'POST /courrier/comptes/' . (int) $args['id'], $e->getMessage());
            }

            return self::redirection($rep, '/courrier');
        });

        $app->post('/courrier/comptes/{id:[0-9]+}/basculer', function (Requete $req, Reponse $rep, array $args) use ($config): Reponse {
            $corps = self::corps($req);
            Fabrique::comptes($config)->basculer((int) $args['id'], (string) ($corps['actif'] ?? '0') === '1');

            return self::redirection($rep, '/courrier');
        });

        // Test de connexion : branche le port injectable (faux en mémoire en test). Ne fait AUCUN envoi.
        $app->post('/courrier/comptes/{id:[0-9]+}/tester', function (Requete $req, Reponse $rep, array $args) use ($config, $admin, $vue): Reponse {
            $compte = Fabrique::comptes($config)->parId((int) $args['id']);
            if ($compte === null) {
                return self::erreur($vue, $rep, 404, 'POST /courrier/comptes/' . (int) $args['id'] . '/tester', 'ce compte n’existe pas');
            }
            $port = Fabrique::portPour($config, $compte);
            try {
                $port->tester();
                $essai = ['compte' => $compte->nom, 'ok' => true, 'message' => 'Connexion acceptée'];
            } catch (\Throwable $e) {
                $essai = ['compte' => $compte->nom, 'ok' => false, 'message' => $e->getMessage()];
            } finally {
                $port->fermer();
            }
            $donnees = $admin->liste();
            $donnees['ecran'] = 'application';
            $donnees['csrf'] = self::csrf($req);
            $donnees['essai'] = $essai;

            return $vue->rendre($rep, 'ecrans/courrier.twig', $donnees);
        });

        // Traitement à la demande : mêmes règles que la tâche de fond, borné à un passage.
        $app->post('/courrier/comptes/{id:[0-9]+}/traiter', function (Requete $req, Reponse $rep, array $args) use ($pdo, $config, $admin, $vue): Reponse {
            $compte = Fabrique::comptes($config)->parId((int) $args['id']);
            if ($compte === null) {
                return self::erreur($vue, $rep, 404, 'POST /courrier/comptes/' . (int) $args['id'] . '/traiter', 'ce compte n’existe pas');
            }
            $port = Fabrique::portPour($config, $compte);
            try {
                $rapport = Fabrique::moteur($config, $pdo)
                    ->traiter($compte, Fabrique::regles($config)->parCompte($compte->nom), $port);
            } finally {
                $port->fermer();
            }
            $donnees = $admin->liste();
            $donnees['ecran'] = 'application';
            $donnees['csrf'] = self::csrf($req);
            $donnees['rapport'] = $rapport;

            return $vue->rendre($rep, 'ecrans/courrier.twig', $donnees);
        });

        // --- Règles ---

        $app->post('/courrier/regles', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = self::corps($req);
            try {
                Fabrique::regles($config)->creer(self::regleDepuisFormulaire($corps));
            } catch (ErreurCourrier $e) {
                return self::erreur($vue, $rep, 422, 'POST /courrier/regles', $e->getMessage());
            }

            return self::redirection($rep, '/courrier');
        });

        $app->get('/courrier/regles/{id:[0-9]+}', function (Requete $req, Reponse $rep, array $args) use ($admin, $vue): Reponse {
            $donnees = $admin->regle((int) $args['id']);
            if ($donnees === null) {
                return self::erreur($vue, $rep, 404, 'GET /courrier/regles/' . (int) $args['id'], 'cette règle n’existe pas');
            }
            $donnees['ecran'] = 'application';
            $donnees['csrf'] = self::csrf($req);

            return $vue->rendre($rep, 'ecrans/courrier-regle.twig', $donnees);
        });

        $app->post('/courrier/regles/{id:[0-9]+}', function (Requete $req, Reponse $rep, array $args) use ($config, $vue): Reponse {
            $corps = self::corps($req);
            try {
                Fabrique::regles($config)->mettreAJour((int) $args['id'], self::regleDepuisFormulaire($corps));
            } catch (ErreurCourrier $e) {
                return self::erreur($vue, $rep, 422, 'POST /courrier/regles/' . (int) $args['id'], $e->getMessage());
            }

            return self::redirection($rep, '/courrier');
        });

        $app->post('/courrier/regles/{id:[0-9]+}/basculer', function (Requete $req, Reponse $rep, array $args) use ($config): Reponse {
            $corps = self::corps($req);
            Fabrique::regles($config)->basculer((int) $args['id'], (string) ($corps['actif'] ?? '0') === '1');

            return self::redirection($rep, '/courrier');
        });

        // --- Journal (équivalent `bulk_delete` de Paperless) : marque les lignes, ne les supprime jamais ---

        $app->post('/courrier/journal/purger', function (Requete $req, Reponse $rep) use ($pdo): Reponse {
            $corps = self::corps($req);
            $identifiants = [];
            foreach ((array) ($corps['mail_ids'] ?? []) as $identifiant) {
                $identifiants[] = (int) $identifiant;
            }
            (new Courrier\Traites($pdo))->purger($identifiants);

            return self::redirection($rep, '/courrier');
        });
    }

    /** @return array<string, mixed> */
    private static function corps(Requete $req): array
    {
        return is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
    }

    /**
     * @param array<string, mixed> $corps
     * @return array<string, mixed>
     */
    private static function compteDepuisFormulaire(array $corps): array
    {
        return [
            'nom' => (string) ($corps['nom'] ?? ''),
            'serveur' => (string) ($corps['serveur'] ?? ''),
            'port' => $corps['port'] ?? null,
            'securite' => (int) ($corps['securite'] ?? Compte::SECURITE_SSL),
            'utilisateur' => (string) ($corps['utilisateur'] ?? ''),
            'mot_de_passe' => (string) ($corps['mot_de_passe'] ?? ''),
            'type_compte' => (int) ($corps['type_compte'] ?? Compte::TYPE_IMAP),
            'jeton_rafraichissement' => isset($corps['jeton_rafraichissement']) ? (string) $corps['jeton_rafraichissement'] : null,
            'expiration' => isset($corps['expiration']) ? (string) $corps['expiration'] : null,
            'proprietaire' => (string) ($corps['proprietaire'] ?? ''),
            'actif' => !empty($corps['actif']),
        ];
    }

    /**
     * @param array<string, mixed> $corps
     * @return array<string, mixed>
     */
    private static function regleDepuisFormulaire(array $corps): array
    {
        $etiquettes = array_values(array_filter(array_map('trim', explode(',', (string) ($corps['etiquettes'] ?? '')))));

        return [
            'nom' => (string) ($corps['nom'] ?? ''),
            'ordre' => (int) ($corps['ordre'] ?? 0),
            'compte' => (string) ($corps['compte'] ?? ''),
            'actif' => !empty($corps['actif']),
            'dossier' => (string) ($corps['dossier'] ?? 'INBOX'),
            'filtre_de' => (string) ($corps['filtre_de'] ?? ''),
            'filtre_a' => (string) ($corps['filtre_a'] ?? ''),
            'filtre_objet' => (string) ($corps['filtre_objet'] ?? ''),
            'filtre_corps' => (string) ($corps['filtre_corps'] ?? ''),
            'filtre_fichiers_inclure' => (string) ($corps['filtre_fichiers_inclure'] ?? ''),
            'filtre_fichiers_exclure' => (string) ($corps['filtre_fichiers_exclure'] ?? ''),
            'age_max' => (int) ($corps['age_max'] ?? 30),
            'type_piece' => (int) ($corps['type_piece'] ?? Regle::PIECES_JOINTES),
            'portee' => (int) ($corps['portee'] ?? Regle::PORTEE_JOINTES),
            'mise_en_page_pdf' => (int) ($corps['mise_en_page_pdf'] ?? Regle::PDF_DEFAUT),
            'action' => (int) ($corps['action'] ?? Regle::ACTION_MARQUER_LU),
            'parametre_action' => (string) ($corps['parametre_action'] ?? ''),
            'titre_depuis' => (int) ($corps['titre_depuis'] ?? Regle::TITRE_OBJET),
            'etiquettes' => $etiquettes,
            'type_document' => trim((string) ($corps['type_document'] ?? '')) ?: null,
            'emetteur_depuis' => (int) ($corps['emetteur_depuis'] ?? Regle::EMETTEUR_RIEN),
            'emetteur_choisi' => (string) ($corps['emetteur_choisi'] ?? ''),
            'proprietaire_depuis_regle' => !empty($corps['proprietaire_depuis_regle']),
        ];
    }

    private static function redirection(Reponse $rep, string $chemin): Reponse
    {
        return $rep->withHeader('Location', $chemin)->withStatus(303);
    }

    private static function csrf(Requete $req): string
    {
        $session = $req->getAttribute('ged.session');
        if (is_array($session) && isset($session['csrf']) && (string) $session['csrf'] !== '') {
            return (string) $session['csrf'];
        }

        return (string) ($req->getCookieParams()['gedv2_csrf'] ?? '');
    }

    private static function erreur(Vue $vue, Reponse $rep, int $code, string $etape, string $cause): Reponse
    {
        return $vue->rendre($rep, 'erreur.twig', [
            'code' => $code,
            'etape' => $etape,
            'cause' => $cause,
            'reference' => substr(hash('sha256', 'courrier:' . $cause . microtime()), 0, 10),
        ], $code);
    }
}
