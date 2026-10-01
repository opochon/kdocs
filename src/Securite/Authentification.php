<?php

declare(strict_types=1);

namespace Ged\Securite;

use Ged\Base;
use Ged\Config;
use PDO;

/** Vérification des identifiants ; le compte synthétique n'existe que dans l'environnement de test. */
final class Authentification
{
    public function __construct(private readonly Config $config, ?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Base::pdo($config);
    }

    private readonly PDO $pdo;

    /** @return array{id:int|null,identifiant:string,permissions:list<string>}|null */
    public function verifier(string $identifiant, string $motDePasse): ?array
    {
        $identifiant = mb_strtolower(trim($identifiant));
        // Le compte synthétique est un raccourci de TEST ; un compte RÉEL en base (créé par
        // l'installateur ou l'écran Utilisateurs) reste vérifiable sous APP_ENV=test, sinon aucune
        // recette réelle ne pourrait se connecter. En production, seule la base décide.
        if ($this->config->get('APP_ENV') === 'test') {
            $attendu = mb_strtolower($this->config->get('AUTH_TEST_LOGIN', 'testeur@gedv2.test'));
            $secret = $this->config->get('AUTH_TEST_PASSWORD', 'MotDePasse-Test-2026!');
            if (hash_equals($attendu, $identifiant) && hash_equals($secret, $motDePasse)) {
                return ['id' => null, 'identifiant' => $attendu, 'permissions' => [
                    'documents:lire', 'documents:traiter', 'recherche:lire', 'workflows:lire', 'application:administrer',
                    // Branchement Workflows (V2-06, signalé dans docs/secteurs/workflows.md) : le compte de test
                    // doit pouvoir gérer une règle et valider une transition, mais pas la refuser (porte 3).
                    'workflow.gerer', 'workflow.executer', 'workflow.transition', 'workflow.transition.valider',
                ]];
            }
        }

        try {
            $requete = $this->pdo->prepare('SELECT id, identifiant, empreinte FROM comptes WHERE LOWER(identifiant) = ? LIMIT 1');
            $requete->execute([$identifiant]);
            $compte = $requete->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if ($compte === false || !password_verify($motDePasse, (string) $compte['empreinte'])) {
            return null;
        }

        $id = (int) $compte['id'];
        $permissions = (new DroitsDossiers($this->pdo))->permissionsPourCompte($id);

        return ['id' => $id, 'identifiant' => (string) $compte['identifiant'], 'permissions' => $permissions];
    }
}
