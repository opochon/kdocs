<?php
/**
 * Script pour définir le mot de passe admin
 * Usage: php set-admin-password.php [password]
 */

require_once __DIR__ . '/../vendor/autoload.php';

$password = $argv[1] ?? 'KDocs2024!';

try {
    $db = \KDocs\Core\Database::getInstance();

    // Lister les utilisateurs
    echo "=== Utilisateurs actuels ===\n";
    $users = $db->query('SELECT id, username, email, is_admin FROM users')->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo "Aucun utilisateur trouvé. Création de l'admin...\n";
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->exec("INSERT INTO users (username, email, password_hash, is_active, is_admin)
                   VALUES ('admin', 'admin@kdocs.local', '$hash', 1, 1)");
        echo "[OK] Utilisateur admin créé avec mot de passe: $password\n";
    } else {
        foreach ($users as $user) {
            echo "- [{$user['id']}] {$user['username']} (admin: {$user['is_admin']})\n";
        }

        // Mettre à jour le mot de passe de l'admin
        $admin = null;
        foreach ($users as $user) {
            if ($user['is_admin']) {
                $admin = $user;
                break;
            }
        }

        if ($admin) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$hash, $admin['id']]);
            echo "\n[OK] Mot de passe de '{$admin['username']}' mis à jour: $password\n";
        } else {
            echo "\n[!] Aucun admin trouvé. Premier utilisateur promu admin.\n";
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare('UPDATE users SET password_hash = ?, is_admin = 1 WHERE id = ?');
            $stmt->execute([$hash, $users[0]['id']]);
            echo "[OK] '{$users[0]['username']}' promu admin avec mot de passe: $password\n";
        }
    }

} catch (Exception $e) {
    echo "[ERREUR] " . $e->getMessage() . "\n";
    exit(1);
}
