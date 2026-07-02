<?php
/**
 * Template: Formulaire d'assignation de rôle
 */
$pageTitle = 'Assigner un rôle - ' . ($user['username'] ?? 'Utilisateur');

?>

<div class="max-w-2xl mx-auto px-4 py-8">
    <div class="mb-6">
        <a href="<?= $basePath ?>/admin/roles" class="flex items-center gap-2" style="color:var(--accent)">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Retour aux rôles
        </a>
    </div>

    <div class="rounded-xl shadow-sm border p-6" style="background:var(--surface);border-color:var(--border)">
        <h1 class="text-xl font-bold mb-2" style="color:var(--ink)">Assigner un rôle</h1>
        <p class="mb-6" style="color:var(--ink-soft)">
            Utilisateur: <strong><?= htmlspecialchars($user['username']) ?></strong>
            <?php if (!empty($user['email'])): ?>
                (<?= htmlspecialchars($user['email']) ?>)
            <?php endif; ?>
        </p>

        <!-- Rôles actuels -->
        <?php if (!empty($userRoles)): ?>
            <div class="mb-6 p-4 rounded-lg" style="background:var(--app-bg)">
                <h3 class="text-sm font-medium mb-2" style="color:var(--ink-soft)">Rôles actuels</h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($userRoles as $ur): ?>
                        <span class="ds-chip ds-chip--accent px-2.5 py-1 text-xs font-medium">
                            <?= htmlspecialchars($ur['label'] ?? $ur['code']) ?>
                            <?php if (($ur['scope'] ?? '*') !== '*'): ?>
                                <span class="ml-1">(<?= htmlspecialchars($ur['scope']) ?>)</span>
                            <?php endif; ?>
                            <?php if (!empty($ur['max_amount'])): ?>
                                <span class="ml-1">&lt;<?= number_format((float)$ur['max_amount'], 0, ',', ' ') ?> CHF</span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <form action="<?= $basePath ?>/admin/roles/<?= $user['id'] ?>/assign" method="POST" class="space-y-6">
            <!-- Sélection du rôle -->
            <div>
                <label class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">Rôle à assigner *</label>
                <select name="role_code" required
                        class="form-select">
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
                <label class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                    Scope (type de document)
                    <span class="font-normal" style="color:var(--dim)">- optionnel</span>
                </label>
                <select name="scope"
                        class="form-select">
                    <option value="*">Tous les types de documents</option>
                    <?php foreach ($documentTypes as $dt): ?>
                        <option value="<?= htmlspecialchars($dt['code']) ?>">
                            <?= htmlspecialchars($dt['label']) ?> (<?= htmlspecialchars($dt['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-sm mt-1" style="color:var(--dim)">Limite le rôle à un type de document spécifique</p>
            </div>

            <!-- Montant maximum -->
            <div>
                <label class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                    Montant maximum (CHF)
                    <span class="font-normal" style="color:var(--dim)">- optionnel</span>
                </label>
                <input type="number" name="max_amount" step="0.01" min="0"
                       class="form-input"
                       placeholder="Ex: 5000">
                <p class="text-sm mt-1" style="color:var(--dim)">Limite le rôle aux documents dont le montant ne dépasse pas cette valeur</p>
            </div>

            <!-- Période de validité -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                        Valide à partir du
                        <span class="font-normal" style="color:var(--dim)">- optionnel</span>
                    </label>
                    <input type="date" name="valid_from"
                           class="form-input">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                        Valide jusqu'au
                        <span class="font-normal" style="color:var(--dim)">- optionnel</span>
                    </label>
                    <input type="date" name="valid_to"
                           class="form-input">
                </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-4 pt-4">
                <button type="submit"
                        class="btn-primary flex-1 px-4 py-2 rounded-lg transition-colors">
                    Assigner le rôle
                </button>
                <a href="<?= $basePath ?>/admin/roles"
                   class="btn-secondary border px-4 py-2 rounded-lg transition-colors text-center">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</div>


