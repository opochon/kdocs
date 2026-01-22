<?php
/**
 * K-Docs - UserRepository
 * Repository pour l'accès aux données utilisateurs
 */

namespace KDocs\Repositories;

use KDocs\Core\Database;
use PDO;

class UserRepository
{
    private Database $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Recherche un utilisateur par son nom d'utilisateur
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM users WHERE username = :username AND is_active = 1'
        );
        $stmt->execute(['username' => $username]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Recherche un utilisateur par son ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Recherche un utilisateur par son email
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM users WHERE email = :email AND is_active = 1'
        );
        $stmt->execute(['email' => $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Met à jour la date de dernière connexion
     */
    public function updateLastLogin(int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE users SET last_login = NOW() WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }
    
    /**
     * Récupère tous les utilisateurs
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM users ORDER BY username');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Crée un nouvel utilisateur
     */
    public function create(array $data, string $password): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, email, password_hash, first_name, last_name, is_active, is_admin, is_superuser)
             VALUES (:username, :email, :password_hash, :first_name, :last_name, :is_active, :is_admin, :is_superuser)'
        );
        
        $stmt->execute([
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'is_active' => ($data['is_active'] ?? true) ? 1 : 0,
            'is_admin' => ($data['is_admin'] ?? false) ? 1 : 0,
            'is_superuser' => ($data['is_superuser'] ?? false) ? 1 : 0,
        ]);
        
        return (int) $this->db->lastInsertId();
    }
    
    /**
     * Met à jour le mot de passe d'un utilisateur
     */
    public function updatePassword(int $userId, string $password): void
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $stmt->execute([
            'id' => $userId,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);
    }
    
    /**
     * Met à jour un utilisateur
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];
        
        $allowed = ['username', 'email', 'first_name', 'last_name', 'is_active', 'is_admin', 'is_superuser'];
        
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = is_bool($data[$field]) ? ($data[$field] ? 1 : 0) : $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Supprime un utilisateur
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
}
