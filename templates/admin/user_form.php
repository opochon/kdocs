<?php
// Formulaire de création/édition d'utilisateur
$isEdit = !empty($user);
?>

<div class="max-w-3xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">
            <?= $isEdit ? 'Modifier l\'utilisateur' : 'Créer un utilisateur' ?>
        </h1>
        <a href="<?= url('/admin/users') ?>" style="color:var(--ink-soft)">
            ← Retour
        </a>
    </div>

    <form method="POST" action="<?= url($isEdit ? '/admin/users/' . $user['id'] . '/save' : '/admin/users/save') ?>" class="rounded-lg shadow p-6 space-y-6" style="background:var(--surface)">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="username" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">
                    Nom d'utilisateur <span style="color:var(--red)">*</span>
                </label>
                <input type="text" id="username" name="username" required
                       value="<?= htmlspecialchars($user['username'] ?? '') ?>"
                       class="form-input">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">
                    Email
                </label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                       class="form-input">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">
                    Mot de passe <?= $isEdit ? '' : '<span style="color:var(--red)">*</span>' ?>
                </label>
                <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?>
                       placeholder="<?= $isEdit ? 'Laisser vide pour ne pas modifier' : '' ?>"
                       class="form-input">
            </div>

            <div>
                <label class="flex items-center">
                    <input type="checkbox" name="is_active" value="1"
                           <?= ($user['is_active'] ?? true) ? 'checked' : '' ?>
                           class="rounded" style="accent-color:var(--accent)">
                    <span class="ml-2 text-sm" style="color:var(--ink-soft)">Utilisateur actif</span>
                </label>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                Groupes <span style="color:var(--red)">*</span>
            </label>
            <p class="text-xs mb-3" style="color:var(--dim)">
                Les permissions de l'utilisateur sont déterminées par ses groupes. Le groupe "Administrateurs" donne tous les droits.
            </p>
            <?php if (!empty($groups)): ?>
            <div class="space-y-2 max-h-64 overflow-y-auto border rounded-lg p-3" style="border-color:var(--border)">
                <?php
                $userGroupIds = array_column($userGroups ?? [], 'id');
                foreach ($groups as $group):
                    $isAdmin = ($group['code'] ?? '') === 'ADMIN';
                ?>
                <label class="flex items-center p-2 rounded-lg cursor-pointer ds-row-hover">
                    <input type="checkbox" name="groups[]" value="<?= $group['id'] ?>"
                           <?= in_array($group['id'], $userGroupIds) ? 'checked' : '' ?>
                           class="rounded" style="accent-color:var(--accent)">
                    <span class="ml-2 text-sm" style="color:var(--ink-soft)">
                        <?= htmlspecialchars($group['name']) ?>
                        <?php if ($isAdmin): ?>
                        <span class="ml-1 px-2 py-0.5 text-xs ds-chip ds-chip--red">Admin</span>
                        <?php endif; ?>
                        <?php if (!empty($group['code']) && !$isAdmin): ?>
                        <span class="ml-1 text-xs" style="color:var(--dim)">(<?= htmlspecialchars($group['code']) ?>)</span>
                        <?php endif; ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-sm" style="color:var(--amber)">
                Aucun groupe disponible. <a href="<?= url('/admin/user-groups/create') ?>" class="underline">Créer un groupe</a>
            </p>
            <?php endif; ?>
        </div>

        <div class="flex justify-end space-x-4">
            <a href="<?= url('/admin/users') ?>" class="btn-secondary border px-4 py-2 rounded-lg">
                Annuler
            </a>
            <button type="submit" class="btn-primary px-4 py-2 rounded-lg">
                <?= $isEdit ? 'Enregistrer' : 'Créer' ?>
            </button>
        </div>
    </form>
</div>
