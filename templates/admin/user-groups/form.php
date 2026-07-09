<?php
/**
 * K-Docs - Formulaire groupe d'utilisateurs
 */
use KDocs\Core\Config;
$base = Config::basePath();
$isEdit = !empty($group);
$isAdminGroup = ($group['code'] ?? '') === 'ADMIN';
?>

<div class="max-w-3xl mx-auto">
    <div class="mb-6">
        <a href="<?= url('/admin/user-groups') ?>" class="text-sm" style="color:var(--ink-soft)">
            <?= icon('arrow-left', ['class' => 'mr-1']) ?> Retour aux groupes
        </a>
        <h1 class="text-2xl font-bold mt-2" style="color:var(--ink)"><?= $isEdit ? 'Modifier le groupe' : 'Nouveau groupe' ?></h1>
    </div>

    <form method="POST" action="<?= url('/admin/user-groups' . ($isEdit ? '/' . $group['id'] : '') . '/save') ?>" class="space-y-6">
        <!-- Informations de base -->
        <div class="rounded-xl shadow-sm border p-6" style="background:var(--surface);border-color:var(--border)">
            <h2 class="text-lg font-medium mb-4" style="color:var(--ink)">Informations générales</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Nom du groupe *</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($group['name'] ?? '') ?>" required
                           class="form-input">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Code (optionnel)</label>
                    <?php if ($isAdminGroup): ?>
                    <input type="text" value="ADMIN" readonly disabled
                           class="form-input cursor-not-allowed" style="background:var(--app-bg);color:var(--dim)">
                    <input type="hidden" name="code" value="ADMIN">
                    <p class="mt-1 text-xs" style="color:var(--dim)">Le code ADMIN est réservé et ne peut pas être modifié</p>
                    <?php else: ?>
                    <input type="text" name="code" value="<?= htmlspecialchars($group['code'] ?? '') ?>"
                           placeholder="ACCOUNTING, SUPERVISORS..."
                           class="form-input">
                    <p class="mt-1 text-xs" style="color:var(--dim)">Code unique pour référencer ce groupe dans les workflows</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-4">
                <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Description</label>
                <textarea name="description" rows="2"
                          class="form-textarea"><?= htmlspecialchars($group['description'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Membres -->
        <div class="rounded-xl shadow-sm border p-6" style="background:var(--surface);border-color:var(--border)">
            <h2 class="text-lg font-medium mb-4" style="color:var(--ink)">Membres du groupe</h2>

            <div class="max-h-64 overflow-y-auto border rounded-lg p-3" style="border-color:var(--border)">
                <?php
                $memberIds = array_column($members ?? [], 'id');
                foreach ($users as $user):
                    $checked = in_array($user['id'], $memberIds);
                ?>
                <label class="flex items-center gap-3 p-2 rounded-lg cursor-pointer ds-row-hover">
                    <input type="checkbox" name="members[]" value="<?= $user['id'] ?>" <?= $checked ? 'checked' : '' ?>
                           class="rounded" style="accent-color:var(--accent)">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center" style="background:var(--hover)">
                            <?= icon('user', ['class' => 'text-sm', 'style' => 'color:var(--dim)']) ?>
                        </div>
                        <div>
                            <span class="text-sm font-medium" style="color:var(--ink)"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></span>
                            <span class="text-xs ml-2" style="color:var(--dim)">(<?= htmlspecialchars($user['username']) ?>)</span>
                        </div>
                    </div>
                </label>
                <?php endforeach; ?>

                <?php if (empty($users)): ?>
                <p class="text-center py-4" style="color:var(--dim)">Aucun utilisateur disponible</p>
                <?php endif; ?>
            </div>

            <p class="mt-2 text-xs" style="color:var(--dim)">
                <?= icon('info-circle', ['class' => 'mr-1']) ?>
                Les membres de ce groupe recevront les demandes d'approbation envoyées au groupe.
            </p>
        </div>

        <!-- Permissions -->
        <?php if ($isAdminGroup): ?>
        <div class="rounded-xl shadow-sm border p-6" style="background:color-mix(in srgb,var(--green) 12%,transparent);border-color:var(--green)">
            <h2 class="text-lg font-medium mb-2" style="color:var(--green)">
                <?= icon('shield-alt', ['class' => 'mr-2']) ?>Permissions
            </h2>
            <p style="color:var(--green)">
                <?= icon('check-circle', ['class' => 'mr-2']) ?>
                <strong>Accès complet.</strong> Les membres du groupe Administrateurs ont automatiquement tous les droits sur l'application.
            </p>
        </div>
        <?php else: ?>
        <div class="rounded-xl shadow-sm border p-6" style="background:var(--surface);border-color:var(--border)">
            <h2 class="text-lg font-medium mb-2" style="color:var(--ink)">Permissions</h2>
            <p class="text-sm mb-4" style="color:var(--dim)">
                Sélectionnez les permissions accordées aux membres de ce groupe.
            </p>

            <?php
            $permissionCategories = [
                'Documents' => [
                    'documents.view' => 'Voir les documents',
                    'documents.create' => 'Créer des documents',
                    'documents.edit' => 'Modifier des documents',
                    'documents.delete' => 'Supprimer des documents',
                    'documents.*' => 'Tous droits documents',
                ],
                'Tags & Métadonnées' => [
                    'tags.view' => 'Voir les tags',
                    'tags.create' => 'Créer des tags',
                    'tags.edit' => 'Modifier des tags',
                    'tags.delete' => 'Supprimer des tags',
                ],
                'Correspondants' => [
                    'correspondents.view' => 'Voir les correspondants',
                    'correspondents.create' => 'Créer des correspondants',
                    'correspondents.edit' => 'Modifier des correspondants',
                    'correspondents.delete' => 'Supprimer des correspondants',
                ],
                'Administration' => [
                    'users.view' => 'Voir les utilisateurs',
                    'users.edit' => 'Gérer les utilisateurs',
                    'users.delete' => 'Supprimer des utilisateurs',
                    'settings.view' => 'Voir les paramètres',
                    'settings.edit' => 'Modifier les paramètres',
                ],
                'Workflows' => [
                    'workflows.view' => 'Voir les workflows',
                    'workflows.edit' => 'Gérer les workflows',
                    'can_approve_invoices' => 'Approuver les factures',
                    'can_approve_contracts' => 'Approuver les contrats',
                ],
                'Autres' => [
                    'export' => 'Exporter des données',
                    'api.access' => 'Accès API',
                ],
            ];

            $currentPerms = $group['permissions'] ?? [];
            ?>

            <div class="space-y-6">
                <?php foreach ($permissionCategories as $category => $perms): ?>
                <div>
                    <h3 class="text-sm font-semibold mb-2" style="color:var(--ink-soft)"><?= $category ?></h3>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach ($perms as $permKey => $permLabel):
                            $checked = isset($currentPerms[$permKey]) ? $currentPerms[$permKey] : (in_array($permKey, $currentPerms) ? true : false);
                        ?>
                        <label class="flex items-center gap-2 p-2 rounded cursor-pointer ds-row-hover">
                            <input type="checkbox" name="permissions[<?= $permKey ?>]" value="1"
                                   <?= $checked ? 'checked' : '' ?>
                                   class="rounded" style="accent-color:var(--accent)">
                            <span class="text-sm" style="color:var(--ink-soft)"><?= $permLabel ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="flex items-center justify-end gap-3">
            <a href="<?= url('/admin/user-groups') ?>" class="btn-secondary border px-4 py-2 rounded-lg">
                Annuler
            </a>
            <button type="submit" class="btn-primary px-6 py-2 rounded-lg">
                <?= icon('save', ['class' => 'mr-2']) ?>Enregistrer
            </button>
        </div>
    </form>
</div>
