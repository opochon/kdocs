<?php
// Formulaire de création/édition d'utilisateur
$isEdit = !empty($user);
?>

<div class="max-w-3xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">
            <?= $isEdit ? 'Modifier l\'utilisateur' : 'Créer un utilisateur' ?>
        </h1>
        <a href="<?= url('/admin/users') ?>" class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">
            ← Retour
        </a>
    </div>

    <form method="POST" action="<?= url($isEdit ? '/admin/users/' . $user['id'] . '/save' : '/admin/users/save') ?>" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Nom d'utilisateur <span class="text-red-500">*</span>
                </label>
                <input type="text" id="username" name="username" required
                       value="<?= htmlspecialchars($user['username'] ?? '') ?>"
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Email
                </label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Mot de passe <?= $isEdit ? '' : '<span class="text-red-500">*</span>' ?>
                </label>
                <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?>
                       placeholder="<?= $isEdit ? 'Laisser vide pour ne pas modifier' : '' ?>"
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
            </div>

            <div>
                <label for="role" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Rôle <span class="text-red-500">*</span>
                </label>
                <select id="role" name="role" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                    <option value="user" <?= ($user['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>Utilisateur</option>
                    <option value="admin" <?= ($user['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrateur</option>
                    <option value="viewer" <?= ($user['role'] ?? '') === 'viewer' ? 'selected' : '' ?>>Lecteur</option>
                </select>
            </div>

            <div>
                <label class="flex items-center">
                    <input type="checkbox" name="is_active" value="1"
                           <?= ($user['is_active'] ?? true) ? 'checked' : '' ?>
                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Utilisateur actif</span>
                </label>
            </div>
        </div>

        <?php if (!empty($groups)): ?>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Groupes
            </label>
            <div class="space-y-2">
                <?php
                $userGroupIds = array_column($userGroups ?? [], 'id');
                foreach ($groups as $group):
                ?>
                <label class="flex items-center">
                    <input type="checkbox" name="groups[]" value="<?= $group['id'] ?>"
                           <?= in_array($group['id'], $userGroupIds) ? 'checked' : '' ?>
                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">
                        <?= htmlspecialchars($group['name']) ?>
                        <?php if (!empty($group['description'])): ?>
                        <span class="text-gray-500">- <?= htmlspecialchars($group['description']) ?></span>
                        <?php endif; ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div>
            <label for="permissions" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Permissions (séparées par des virgules)
            </label>
            <?php
            $permissionsValue = '';
            if (!empty($user) && isset($user['permissions'])) {
                if (is_array($user['permissions'])) {
                    $permissionsValue = implode(', ', $user['permissions']);
                } else {
                    $permissionsValue = (string)$user['permissions'];
                }
            }
            ?>
            <input type="text" id="permissions" name="permissions"
                   value="<?= htmlspecialchars($permissionsValue) ?>"
                   placeholder="documents.view, documents.create, documents.edit"
                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Utilisez "*" pour toutes les permissions, ou listez les permissions séparées par des virgules.
            </p>
        </div>

        <div class="flex justify-end space-x-4">
            <a href="<?= url('/admin/users') ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                Annuler
            </a>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <?= $isEdit ? 'Enregistrer' : 'Créer' ?>
            </button>
        </div>
    </form>
</div>
