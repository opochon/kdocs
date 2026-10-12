<?php

declare(strict_types=1);

namespace Ged\Securite;

use Ged\Config;
use Ged\Base;
use Ged\Ui\Vue;
use PDOException;
use Psr\Http\Message\ResponseInterface as Reponse;
use Slim\App;

/** Point d'entrée autonome du secteur, appelé automatiquement par Kernel. */
final class Module
{
    /** @param App<\Psr\Container\ContainerInterface> $app */
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $dossier = $config->chemin('SECURITE_DOSSIER', 'var/securite');
        $sessions = new RegistreSessions($dossier . '/sessions');
        $audit = new JournalAudit($dossier . '/audit.ndjson');
        $pdo = Base::pdo($config);
        $controleur = new ConnexionController(
            new Authentification($config, $pdo), $sessions, new ProtectionBruteForce($dossier . '/brute-force.json'), $audit, $vue,
        );

        $app->get('/connexion', [$controleur, 'formulaire']);
        $app->post('/connexion', [$controleur, 'connecter']);
        $app->post('/deconnexion', [$controleur, 'deconnecter']);
        $app->get('/administration/comptes', function ($req, $rep) use ($pdo, $vue) {
            try {
                return $vue->rendre($rep, 'securite/comptes.twig', [
                    'ecran' => 'administration',
                    'comptes' => $pdo->query('SELECT id, identifiant, role FROM comptes ORDER BY identifiant')->fetchAll(),
                    // Lot ged-parite-ecrans-1 : le formulaire de création était rendu SANS jeton CSRF, la
                    // création par l'écran était donc impossible. Jeton de la session courante (ligne
                    // hors secteur, signalée dans docs/secteurs/interface.md).
                    'csrf' => \Ged\Ui\Ecrans::jetonCsrf($req),
                ]);
            } catch (PDOException) {
                return self::schemaIndisponible($vue, $rep, 'GET /administration/comptes');
            }
        });
        $app->post('/administration/comptes', function ($req, $rep) use ($pdo, $audit) {
            $corps = (array) ($req->getParsedBody() ?: []);
            $identifiant = mb_strtolower(trim((string) ($corps['identifiant'] ?? '')));
            $motDePasse = (string) ($corps['mot_de_passe'] ?? '');
            $role = (string) ($corps['role'] ?? '');
            if (filter_var($identifiant, FILTER_VALIDATE_EMAIL) === false || strlen($motDePasse) < 12
                || !self::roleActif($pdo, $role)) {
                throw new \Slim\Exception\HttpBadRequestException($req, 'Compte administratif invalide.');
            }
            $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
                ->execute([$identifiant, password_hash($motDePasse, PASSWORD_DEFAULT), $role]);
            $audit->ajouter('changement_role', self::auteur($req), [
                'cible' => 'compte:' . $identifiant, 'ancien' => null, 'nouveau' => $role,
            ]);

            return $rep->withHeader('Location', '/administration/comptes')->withStatus(303);
        });
        $app->get('/administration/groupes', function ($req, $rep) use ($pdo, $vue) {
            try {
                return $vue->rendre($rep, 'securite/groupes.twig', [
                    'ecran' => 'administration',
                    'groupes' => $pdo->query('SELECT id, nom, role_code FROM securite_groupes ORDER BY nom')->fetchAll(),
                    'csrf' => \Ged\Ui\Ecrans::jetonCsrf($req),
                ]);
            } catch (PDOException) {
                return self::schemaIndisponible($vue, $rep, 'GET /administration/groupes');
            }
        });
        $app->post('/administration/groupes', function ($req, $rep) use ($pdo, $audit) {
            $corps = (array) ($req->getParsedBody() ?: []);
            $nom = trim((string) ($corps['nom'] ?? ''));
            $role = (string) ($corps['role'] ?? '');
            if ($nom === '' || mb_strlen($nom) > 128 || !self::roleActif($pdo, $role)) {
                throw new \Slim\Exception\HttpBadRequestException($req, 'Groupe administratif invalide.');
            }
            $pdo->prepare('INSERT INTO securite_groupes (nom, role_code) VALUES (?, ?)')->execute([$nom, $role]);
            $audit->ajouter('changement_role', self::auteur($req), [
                'cible' => 'groupe:' . $nom, 'ancien' => null, 'nouveau' => $role,
            ]);

            return $rep->withHeader('Location', '/administration/groupes')->withStatus(303);
        });
        $app->get('/administration/roles', function ($req, $rep) use ($pdo, $vue) {
            try {
                return $vue->rendre($rep, 'securite/roles.twig', [
                    'ecran' => 'administration',
                    'roles' => $pdo->query('SELECT code, libelle FROM securite_roles WHERE actif = 1 ORDER BY code')->fetchAll(),
                ]);
            } catch (PDOException) {
                return self::schemaIndisponible($vue, $rep, 'GET /administration/roles');
            }
        });
        $app->get('/dossiers/{id:[0-9]+}/droits', function ($req, $rep, array $args) use ($pdo, $vue) {
            try {
                $stmt = $pdo->prepare('SELECT g.id, g.nom, d.action_code, d.autorise FROM securite_groupes g LEFT JOIN securite_dossiers_droits d ON d.groupe_id = g.id AND d.dossier_id = ? ORDER BY g.nom');
                $stmt->execute([(int) $args['id']]);
                return $vue->rendre($rep, 'securite/droits-dossier.twig', [
                    'ecran' => 'administration',
                    'dossier' => (int) $args['id'],
                    'regles' => $stmt->fetchAll(),
                ]);
            } catch (PDOException) {
                return self::schemaIndisponible($vue, $rep, 'GET /dossiers/' . (int) $args['id'] . '/droits');
            }
        });
        $app->post('/dossiers/{id:[0-9]+}/droits', function ($req, $rep, array $args) use ($pdo, $audit) {
            $corps = (array) ($req->getParsedBody() ?: []);
            $groupe = (int) ($corps['groupe'] ?? 0);
            $niveau = (string) ($corps['niveau'] ?? '');
            $dossier = (int) $args['id'];
            if ($groupe < 1 || !in_array($niveau, ['lire', 'deposer', 'modifier', 'gerer'], true)
                || !self::aclCibleExiste($pdo, $dossier, $groupe)) {
                throw new \Slim\Exception\HttpBadRequestException($req, 'Règle de droit invalide.');
            }
            $auteur = self::auteur($req);
            $autorise = (string) ($corps['autorise'] ?? '') === '1';
            (new DroitsDossiers($pdo))->poserRegle($dossier, $groupe, $niveau, $autorise);
            $audit->changementDroit($auteur, 'dossier:' . $dossier, $niveau, $autorise);
            return $rep->withHeader('Location', '/dossiers/' . $dossier . '/droits')->withStatus(303);
        });
        $app->add(new GardeCentrale($sessions, $audit, $vue, new DroitsDossiers($pdo)));
    }

    private static function aclCibleExiste(\PDO $pdo, int $dossier, int $groupe): bool
    {
        $requete = $pdo->prepare('SELECT (SELECT COUNT(*) FROM securite_dossiers WHERE id = ?) + '
            . '(SELECT COUNT(*) FROM securite_groupes WHERE id = ?)');
        $requete->execute([$dossier, $groupe]);

        return (int) $requete->fetchColumn() === 2;
    }

    private static function schemaIndisponible(Vue $vue, Reponse $reponse, string $etape): Reponse
    {
        return $vue->rendre($reponse, 'erreur.twig', [
            'code' => 503,
            'etape' => $etape,
            'cause' => 'Schéma d’administration absent ou indisponible : exécutez tools/installer.php.',
            'reference' => substr(hash('sha256', 'schema-administration:' . $etape), 0, 10),
        ], 503);
    }

    private static function roleActif(\PDO $pdo, string $role): bool
    {
        $requete = $pdo->prepare('SELECT 1 FROM securite_roles WHERE code = ? AND actif = 1');
        $requete->execute([$role]);

        return $requete->fetchColumn() !== false;
    }

    private static function auteur(\Psr\Http\Message\ServerRequestInterface $requete): string
    {
        $session = $requete->getAttribute('ged.session');

        return is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';
    }
}
