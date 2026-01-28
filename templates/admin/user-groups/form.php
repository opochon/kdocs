<?php
/**
 * K-Docs - Formulaire groupe d'utilisateurs
 */
use KDocs\Core\Config;
$base = Config::basePath();
$isEdit = !empty($group);
?>

<div class="max-w-3xl mx-auto">
    <div class="mb-6">
        <a href="<?= url('/admin/user-groups') ?>" class="text-sm text-gray-500 hover:text-gray-700">
            <i class="fas fa-arrow-left mr-1"></i> Retour aux groupes
        </a>
        <h1 class="text-2xl font-bold text-gray-900 mt-2"><?= $isEdit ? 'Modifier le groupe' : 'Nouveau groupe' ?></h1>
    </div>
    
    <form method="POST" action="<?= url('/admin/user-groups' . ($isEdit ? '/' . $group['id'] : '') . '/save') ?>" class="space-y-6">
        <!-- Informations de base -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Informations générales</h2>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom du groupe *</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($group['name'] ?? '') ?>" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Code (optionnel)</label>
                    <input type="text" name="code" value="<?= htmlspecialchars($group['code'] ?? '') ?>" 
                           placeholder="ACCOUNTING, SUPERVISORS..."
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="mt-1 text-xs text-gray-500">Code unique pour référencer ce groupe dans les workflows</p>
                </div>
            </div>
            
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="2"
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?= htmlspecialchars($group['description'] ?? '') ?></textarea>
            </div>
        </div>
        
        <!-- Membres -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Membres du groupe</h2>
            
            <div class="max-h-64 overflow-y-auto border border-gray-200 rounded-lg p-3">
                <?php 
                $memberIds = array_column($members ?? [], 'id');
                foreach ($users as $user): 
                    $checked = in_array($user['id'], $memberIds);
                ?>
                <label class="flex items-center gap-3 p-2 hover:bg-gray-50 rounded-lg cursor-pointer">
                    <input type="checkbox" name="members[]" value="<?= $user['id'] ?>" <?= $checked ? 'checked' : '' ?>
                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center">
                            <i class="fas fa-user text-gray-500 text-sm"></i>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></span>
                            <span class="text-xs text-gray-500 ml-2">(<?= htmlspecialchars($user['username']) ?>)</span>
                        </div>
                    </div>
                </label>
                <?php endforeach; ?>
                
                <?php if (empty($users)): ?>
                <p class="text-gray-500 text-center py-4">Aucun utilisateur disponible</p>
                <?php endif; ?>
            </div>
            
            <p class="mt-2 text-xs text-gray-500">
                <i class="fas fa-info-circle mr-1"></i>
                Les membres de ce groupe recevront les demandes d'approbation envoyées au groupe.
            </p>
        </div>
        
        <!-- Permissions -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-2">Permissions</h2>
            <p class="text-sm text-gray-500 mb-4">
                Sélectionnez les permissions accordées aux membres de ce groupe. Le groupe "Administrateurs" (code ADMIN) a automatiquement tous les droits.
            </p>

            <?php
            // Définir les catégories de permissions
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
                    <h3 class="text-sm font-semibold text-gray-700 mb-2"><?= $category ?></h3>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach ($perms as $permKey => $permLabel):
                            $checked = isset($currentPerms[$permKey]) ? $currentPerms[$permKey] : (in_array($permKey, $currentPerms) ? true : false);
                        ?>
                        <label class="flex items-center gap-2 p-2 hover:bg-gray-50 rounded cursor-pointer">
                            <input type="checkbox" name="permissions[<?= $permKey ?>]" value="1"
                                   <?= $checked ? 'checked' : '' ?>
                                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-700"><?= $permLabel ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="flex items-center justify-end gap-3">
            <a href="<?= url('/admin/user-groups') ?>" class="px-4 py-2 text-gray-700 hover:text-gray-900">
                Annuler
            </a>
            <button type="submit" class="px-6 py-2 bg-gray-900 text-white rounded-lg hover:bg-gray-800">
                <i class="fas fa-save mr-2"></i>Enregistrer
            </button>
        </div>
    </form>
</div>
