<?php
// Formulaire de création/édition de compte email
$isEdit = !empty($account);
?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
        <?= $isEdit ? 'Modifier le compte email' : 'Nouveau compte email' ?>
    </h1>

    <form method="POST" action="<?= url($isEdit ? '/admin/mail-accounts/' . $account['id'] . '/save' : '/admin/mail-accounts/save') ?>" class="space-y-6">
        <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= $account['id'] ?>">
        <?php endif; ?>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Informations du compte</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom *</label>
                    <input type="text" id="name" name="name" required
                           value="<?= htmlspecialchars($account['name'] ?? '') ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div>
                    <label for="imap_server" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Serveur IMAP *</label>
                    <input type="text" id="imap_server" name="imap_server" required
                           value="<?= htmlspecialchars($account['imap_server'] ?? '') ?>"
                           placeholder="imap.example.com"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div>
                    <label for="imap_port" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Port *</label>
                    <input type="number" id="imap_port" name="imap_port" required
                           value="<?= $account['imap_port'] ?? 993 ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div>
                    <label for="imap_security" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sécurité *</label>
                    <select id="imap_security" name="imap_security" required
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                        <option value="ssl" <?= ($account['imap_security'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="tls" <?= ($account['imap_security'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                        <option value="none" <?= ($account['imap_security'] ?? '') === 'none' ? 'selected' : '' ?>>Aucune</option>
                    </select>
                </div>

                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom d'utilisateur *</label>
                    <input type="text" id="username" name="username" required
                           value="<?= htmlspecialchars($account['username'] ?? '') ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mot de passe <?= $isEdit ? '(laisser vide pour ne pas modifier)' : '*' ?></label>
                    <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?>
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div>
                    <label for="is_active" class="flex items-center">
                        <input type="checkbox" id="is_active" name="is_active" value="1"
                               <?= ($account['is_active'] ?? true) ? 'checked' : '' ?>
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Compte actif</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="flex justify-end space-x-3">
            <a href="<?= url('/admin/mail-accounts') ?>" class="btn-secondary">Annuler</a>
            <button type="submit" class="btn-primary">Enregistrer</button>
        </div>
    </form>
</div>
