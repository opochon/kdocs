<?php

declare(strict_types=1);

namespace Ged\Securite;

use Ged\Config;

/** Vérification des identifiants ; le compte synthétique n'existe que dans l'environnement de test. */
final class Authentification
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{identifiant:string,permissions:list<string>}|null */
    public function verifier(string $identifiant, string $motDePasse): ?array
    {
        $identifiant = mb_strtolower(trim($identifiant));
        if ($this->config->get('APP_ENV') === 'test') {
            $attendu = mb_strtolower($this->config->get('AUTH_TEST_LOGIN', 'testeur@gedv2.test'));
            $secret = $this->config->get('AUTH_TEST_PASSWORD', 'MotDePasse-Test-2026!');
            if (hash_equals($attendu, $identifiant) && hash_equals($secret, $motDePasse)) {
                return ['identifiant' => $attendu, 'permissions' => [
                    'documents:lire', 'documents:traiter', 'recherche:lire', 'workflows:lire', 'application:administrer',
                ]];
            }
            return null;
        }

        $attendu = mb_strtolower($this->config->get('AUTH_LOGIN'));
        $hash = $this->config->get('AUTH_PASSWORD_HASH');
        if ($attendu === '' || $hash === '' || !hash_equals($attendu, $identifiant) || !password_verify($motDePasse, $hash)) {
            return null;
        }

        return ['identifiant' => $attendu, 'permissions' => ['documents:lire']];
    }
}
