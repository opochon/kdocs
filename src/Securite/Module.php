<?php

declare(strict_types=1);

namespace Ged\Securite;

use Ged\Config;
use Ged\Base;
use Ged\Ui\Vue;
use Slim\App;

/** Point d'entrée autonome du secteur, appelé automatiquement par Kernel. */
final class Module
{
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $dossier = $config->chemin('SECURITE_DOSSIER', 'var/securite');
        $sessions = new RegistreSessions($dossier . '/sessions');
        $audit = new JournalAudit($dossier . '/audit.ndjson');
        $pdo = Base::pdo($config);
        \Ged\Socle\Schema::installer($pdo);
        Schema::installer($pdo);
        $controleur = new ConnexionController(
            new Authentification($config, $pdo), $sessions, new ProtectionBruteForce($dossier . '/brute-force.json'), $audit, $vue,
        );

        $app->get('/connexion', [$controleur, 'formulaire']);
        $app->post('/connexion', [$controleur, 'connecter']);
        $app->post('/deconnexion', [$controleur, 'deconnecter']);
        $app->get('/administration/comptes', function ($req, $rep) use ($pdo, $vue) {
            return $vue->rendre($rep, 'securite/comptes.twig', [
                'ecran' => 'administration',
                'comptes' => $pdo->query('SELECT id, identifiant, role FROM comptes ORDER BY identifiant')->fetchAll(),
            ]);
        });
        $app->get('/administration/groupes', function ($req, $rep) use ($pdo, $vue) {
            return $vue->rendre($rep, 'securite/groupes.twig', [
                'ecran' => 'administration',
                'groupes' => $pdo->query('SELECT id, nom, role_code FROM securite_groupes ORDER BY nom')->fetchAll(),
            ]);
        });
        $app->get('/administration/roles', function ($req, $rep) use ($pdo, $vue) {
            return $vue->rendre($rep, 'securite/roles.twig', [
                'ecran' => 'administration',
                'roles' => $pdo->query('SELECT code, libelle FROM securite_roles WHERE actif = 1 ORDER BY code')->fetchAll(),
            ]);
        });
        $app->get('/dossiers/{id:[0-9]+}/droits', function ($req, $rep, array $args) use ($pdo, $vue) {
            $stmt = $pdo->prepare('SELECT g.id, g.nom, d.action_code, d.autorise FROM securite_groupes g LEFT JOIN securite_dossiers_droits d ON d.groupe_id = g.id AND d.dossier_id = ? ORDER BY g.nom');
            $stmt->execute([(int) $args['id']]);
            return $vue->rendre($rep, 'securite/droits-dossier.twig', [
                'ecran' => 'administration',
                'dossier' => (int) $args['id'],
                'regles' => $stmt->fetchAll(),
            ]);
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
            $session = $req->getAttribute('ged.session');
            $auteur = is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';
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
}
