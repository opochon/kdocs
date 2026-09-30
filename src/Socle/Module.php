<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Ui\Vue;
use PDO;
use PDOException;
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
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        // Auto-guérison paresseuse : une sonde à lecture seule par requête, le schéma n'est posé que
        // s'il manque. Poser les CREATE TABLE à chaque requête prendrait un verrou de métadonnées à
        // chaque appel — sous charge, les lectures se mettraient en file derrière leurs propres
        // tables (mesuré : p95 lecture à 21 s). L'écran ne doit jamais être muet parce que la base
        // n'existe pas encore, mais il ne doit pas non plus payer le schéma à chaque page.
        // La nomenclature et les vues vivent dans le fonds (.ged/, V2-04) : la graine s'y pose à la
        // première lecture, il n'y a rien à amorcer en base au-delà de l'index.
        try {
            Base::pdo($config)->query('SELECT 1 FROM documents LIMIT 1')->fetchColumn();
        } catch (PDOException) {
            try {
                Base::assurerBase($config);
                Schema::installer(Base::pdo($config));
            } catch (PDOException $e) {
                error_log('[GEDv2 socle] amorçage de la base impossible : ' . $e->getMessage());
            }
        }

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

        // Créer un champ personnalisé typé sur un type de la nomenclature.
        $app->post('/metadonnees/champ', function (Requete $req, Reponse $rep) use ($config, $vue): Reponse {
            $corps = is_array($req->getParsedBody()) ? $req->getParsedBody() : [];
            $options = array_values(array_filter(array_map('trim', explode("\n", (string) ($corps['options'] ?? ''))),
                static fn (string $o) => $o !== ''));
            try {
                (new Champs($config))->ajouter((int) ($corps['type'] ?? 0), (string) ($corps['nom'] ?? ''),
                    (string) ($corps['nature'] ?? ''), $options);

                return $rep->withHeader('Location', '/metadonnees?type=' . (int) ($corps['type'] ?? 0))->withStatus(303);
            } catch (RuntimeException $e) {
                return self::nommer($vue, $rep, 'POST /metadonnees/champ', $e->getMessage());
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
