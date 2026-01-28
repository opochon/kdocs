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
                <label class="flex items-center">
                    <input type="checkbox" name="is_active" value="1"
                           <?= ($user['is_active'] ?? true) ? 'checked' : '' ?>
                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Utilisateur actif</span>
                </label>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Groupes <span class="text-red-500">*</span>
            </label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                Les permissions de l'utilisateur sont déterminées par ses groupes. Le groupe "Administrateurs" donne tous les droits.
            </p>
            <?php if (!empty($groups)): ?>
            <div class="space-y-2 max-h-64 overflow-y-auto border border-gray-200 dark:border-gray-600 rounded-lg p-3">
                <?php
                $userGroupIds = array_column($userGroups ?? [], 'id');
                foreach ($groups as $group):
                    $isAdmin = ($group['code'] ?? '') === 'ADMIN';
                ?>
                <label class="flex items-center p-2 hover:bg-gray-50 dark:hover:bg-gray-700 rounded-lg cursor-pointer">
                    <input type="checkbox" name="groups[]" value="<?= $group['id'] ?>"
                           <?= in_array($group['id'], $userGroupIds) ? 'checked' : '' ?>
                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">
                        <?= htmlspecialchars($group['name']) ?>
                        <?php if ($isAdmin): ?>
                        <span class="ml-1 px-2 py-0.5 text-xs bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200 rounded">Admin</span>
                        <?php endif; ?>
                        <?php if (!empty($group['code']) && !$isAdmin): ?>
                        <span class="ml-1 text-xs text-gray-400">(<?= htmlspecialchars($group['code']) ?>)</span>
                        <?php endif; ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-sm text-amber-600 dark:text-amber-400">
                Aucun groupe disponible. <a href="<?= url('/admin/user-groups/create') ?>" class="underline">Créer un groupe</a>
            </p>
            <?php endif; ?>
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
