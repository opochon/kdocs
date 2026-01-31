<?php
/**
 * Interface d'authentification partagee
 * Permet aux apps d'utiliser l'auth K-Docs ou leur propre systeme
 *
 * @package KDocs\Shared\Auth
 */

namespace KDocs\Shared\Auth;

interface AuthInterface
{
    /**
     * Verifie si l'utilisateur est connecte
     */
    public function check(): bool;

    /**
     * Recupere l'utilisateur courant
     */
    public function user(): ?array;

    /**
     * Recupere l'ID de l'utilisateur courant
     */
    public function id(): ?int;

    /**
     * Verifie si l'utilisateur a un role
     */
    public function hasRole(string $role): bool;

    /**
     * Verifie si l'utilisateur a une permission
     */
    public function can(string $permission): bool;

    /**
     * Connecte un utilisateur
     */
    public function login(string $username, string $password): bool;

    /**
     * Deconnecte l'utilisateur
     */
    public function logout(): void;
}
