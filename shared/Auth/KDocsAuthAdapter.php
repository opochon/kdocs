<?php
/**
 * Adaptateur pour utiliser l'auth K-Docs dans les apps
 *
 * @package KDocs\Shared\Auth
 */

namespace KDocs\Shared\Auth;

use KDocs\Core\Auth;

class KDocsAuthAdapter implements AuthInterface
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = new Auth();
    }

    public function check(): bool
    {
        return $this->auth->check();
    }

    public function user(): ?array
    {
        return $this->auth->user();
    }

    public function id(): ?int
    {
        $user = $this->user();
        return $user ? (int) $user['id'] : null;
    }

    public function hasRole(string $role): bool
    {
        return $this->auth->hasRole($role);
    }

    public function can(string $permission): bool
    {
        // K-Docs n'a pas de systeme de permissions granulaires pour l'instant
        // On verifie juste si l'utilisateur est connecte
        return $this->check();
    }

    public function login(string $username, string $password): bool
    {
        return $this->auth->attempt($username, $password);
    }

    public function logout(): void
    {
        $this->auth->logout();
    }
}
