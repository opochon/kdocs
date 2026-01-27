<?php
/**
 * Template: Formulaire d'assignation de rôle
 */
$pageTitle = 'Assigner un rôle - ' . ($user['username'] ?? 'Utilisateur');
include __DIR__ . '/../layout/header.php';
?>

<div class="max-w-2xl mx-auto px-4 py-8">
    <div class="mb-6">
        <a href="<?= $basePath ?>/admin/roles" class="text-blue-600 hover:text-blue-800 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Retour aux rôles
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h1 class="text-xl font-bold text-gray-900 mb-2">Assigner un rôle</h1>
        <p class="text-gray-600 mb-6">
            Utilisateur: <strong><?= htmlspecialchars($user['username']) ?></strong>
            <?php if (!empty($user['email'])): ?>
                (<?= htmlspecialchars($user['email']) ?>)
            <?php endif; ?>
        </p>

        <!-- Rôles actuels -->
        <?php if (!empty($userRoles)): ?>
            <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Rôles actuels</h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($userRoles as $ur): ?>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <?= htmlspecialchars($ur['label'] ?? $ur['code']) ?>
                            <?php if (($ur['scope'] ?? '*') !== '*'): ?>
                                <span class="ml-1 text-blue-600">(<?= htmlspecialchars($ur['scope']) ?>)</span>
                            <?php endif; ?>
                            <?php if (!empty($ur['max_amount'])): ?>
                                <span class="ml-1 text-blue-600">&lt;<?= number_format((float)$ur['max_amount'], 0, ',', ' ') ?> CHF</span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <form action="<?= $basePath ?>/admin/roles/<?= $user['id'] ?>/assign" method="POST" class="space-y-6">
            <!-- Sélection du rôle -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Rôle à assigner *</label>
                <select name="role_code" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">-- Sélectionner un rôle --</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= htmlspecialchars($role['code']) ?>">
                            <?= htmlspecialchars($role['label'] ?? $role['code']) ?>
                            (Niveau <?= $role['level'] ?? 0 ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Scope (type de document) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Scope (type de document)
                    <span class="text-gray-500 font-normal">- optionnel</span>
                </label>
                <select name="scope"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="*">Tous les types de documents</option>
                    <?php foreach ($documentTypes as $dt): ?>
                        <option value="<?= htmlspecialchars($dt['code']) ?>">
                            <?= htmlspecialchars($dt['label']) ?> (<?= htmlspecialchars($dt['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-sm text-gray-500 mt-1">Limite le rôle à un type de document spécifique</p>
            </div>

            <!-- Montant maximum -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Montant maximum (CHF)
                    <span class="text-gray-500 font-normal">- optionnel</span>
                </label>
                <input type="number" name="max_amount" step="0.01" min="0"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Ex: 5000">
                <p class="text-sm text-gray-500 mt-1">Limite le rôle aux documents dont le montant ne dépasse pas cette valeur</p>
            </div>

            <!-- Période de validité -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Valide à partir du
                        <span class="text-gray-500 font-normal">- optionnel</span>
                    </label>
                    <input type="date" name="valid_from"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Valide jusqu'au
                        <span class="text-gray-500 font-normal">- optionnel</span>
                    </label>
                    <input type="date" name="valid_to"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-4 pt-4">
                <button type="submit"
                        class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    Assigner le rôle
                </button>
                <a href="<?= $basePath ?>/admin/roles"
                   class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors text-center">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
