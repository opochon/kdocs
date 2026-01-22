<?php
// Template partiel pour le formulaire d'action de workflow
// Utilisé dans workflow_form.php
// $action : données de l'action (peut être null pour nouvelle action)
// $index : index de l'action dans le tableau
// $tags, $correspondents, $documentTypes, $storagePaths, $customFields, $users, $groups : listes pour les dropdowns

$actionType = isset($action) && isset($action['action_type']) ? (int)$action['action_type'] : 1; // 1=Assignment par défaut
$actionTypes = [
    1 => 'Assignation',
    2 => 'Suppression',
    3 => 'Email',
    4 => 'Webhook'
];

// Décoder les valeurs JSON si elles existent
$assignTags = (isset($action) && isset($action['assign_tags'])) ? json_decode($action['assign_tags'], true) : [];
$assignViewUsers = (isset($action) && isset($action['assign_view_users'])) ? json_decode($action['assign_view_users'], true) : [];
$assignViewGroups = (isset($action) && isset($action['assign_view_groups'])) ? json_decode($action['assign_view_groups'], true) : [];
$assignChangeUsers = (isset($action) && isset($action['assign_change_users'])) ? json_decode($action['assign_change_users'], true) : [];
$assignChangeGroups = (isset($action) && isset($action['assign_change_groups'])) ? json_decode($action['assign_change_groups'], true) : [];
$assignCustomFields = (isset($action) && isset($action['assign_custom_fields'])) ? json_decode($action['assign_custom_fields'], true) : [];
$removeTags = (isset($action) && isset($action['remove_tags'])) ? json_decode($action['remove_tags'], true) : [];
$removeCorrespondents = (isset($action) && isset($action['remove_correspondents'])) ? json_decode($action['remove_correspondents'], true) : [];
$removeDocumentTypes = (isset($action) && isset($action['remove_document_types'])) ? json_decode($action['remove_document_types'], true) : [];
$removeStoragePaths = (isset($action) && isset($action['remove_storage_paths'])) ? json_decode($action['remove_storage_paths'], true) : [];
$removeCustomFields = (isset($action) && isset($action['remove_custom_fields'])) ? json_decode($action['remove_custom_fields'], true) : [];
$removeOwners = (isset($action) && isset($action['remove_owners'])) ? json_decode($action['remove_owners'], true) : [];
$removeViewUsers = (isset($action) && isset($action['remove_view_users'])) ? json_decode($action['remove_view_users'], true) : [];
$removeViewGroups = (isset($action) && isset($action['remove_view_groups'])) ? json_decode($action['remove_view_groups'], true) : [];
$removeChangeUsers = (isset($action) && isset($action['remove_change_users'])) ? json_decode($action['remove_change_users'], true) : [];
$removeChangeGroups = (isset($action) && isset($action['remove_change_groups'])) ? json_decode($action['remove_change_groups'], true) : [];
?>

<div class="action-item p-4 border border-gray-200 dark:border-gray-700 rounded-lg" data-action-index="<?= $index ?>">
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2">
            <span class="text-sm font-medium text-gray-500"><?= $index + 1 ?>.</span>
            <select name="actions[<?= $index ?>][action_type]" 
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100 action-type-select"
                    onchange="updateActionConfig(this)">
                <?php foreach ($actionTypes as $value => $label): ?>
                <option value="<?= $value ?>" <?= $actionType == $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" onclick="removeAction(this)" class="text-red-600 hover:text-red-800">
            <i class="fas fa-trash"></i> Supprimer
        </button>
    </div>
    
    <!-- Configuration selon le type d'action -->
    <div class="action-config-fields" data-action-type="<?= $actionType ?>">
        
        <!-- Assignment (1) -->
        <div class="assignment-fields" style="display: <?= $actionType == 1 ? 'block' : 'none' ?>;">
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Attribuer un titre</label>
                        <input type="text" 
                               name="actions[<?= $index ?>][assign_title]" 
                               value="<?= htmlspecialchars(isset($action['assign_title']) ? $action['assign_title'] : '') ?>"
                               placeholder="Peut inclure certains caractères génériques"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Assigner des étiquettes</label>
                        <select name="actions[<?= $index ?>][assign_tags][]" 
                                multiple
                                size="5"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            <?php foreach ($tags as $tag): ?>
                            <option value="<?= $tag['id'] ?>" <?= in_array($tag['id'], $assignTags) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tag['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Ctrl+clic pour sélectionner plusieurs</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Affectation du type de document</label>
                        <select name="actions[<?= $index ?>][assign_document_type]" 
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            <option value="">Aucun</option>
                            <?php foreach ($documentTypes as $dt): ?>
                            <option value="<?= $dt['id'] ?>" <?= (isset($action['assign_document_type']) && $action['assign_document_type'] == $dt['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dt['label']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Affecter le correspondant</label>
                        <select name="actions[<?= $index ?>][assign_correspondent]" 
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            <option value="">Aucun</option>
                            <?php foreach ($correspondents as $corr): ?>
                            <option value="<?= $corr['id'] ?>" <?= (isset($action['assign_correspondent']) && $action['assign_correspondent'] == $corr['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($corr['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Attribuer un chemin de stockage</label>
                        <select name="actions[<?= $index ?>][assign_storage_path]" 
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            <option value="">Aucun</option>
                            <?php foreach ($storagePaths as $sp): ?>
                            <option value="<?= $sp['id'] ?>" <?= (isset($action['assign_storage_path']) && $action['assign_storage_path'] == $sp['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sp['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Affecter des champs personnalisés</label>
                        <select name="actions[<?= $index ?>][assign_custom_fields][]" 
                                multiple
                                size="3"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            <?php foreach ($customFields as $cf): ?>
                            <option value="<?= $cf['id'] ?>" <?= in_array($cf['id'], $assignCustomFields) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cf['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Propriétaire</label>
                        <select name="actions[<?= $index ?>][assign_owner]" 
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            <option value="">Aucun</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= (isset($action['assign_owner']) && $action['assign_owner'] == $u['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['username'] . ($u['first_name'] || $u['last_name'] ? ' (' . trim($u['first_name'] . ' ' . $u['last_name']) . ')' : '')) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Assigner des autorisations de vue</h4>
                        <div class="space-y-2">
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Utilisateurs :</label>
                                <select name="actions[<?= $index ?>][assign_view_users][]" 
                                        multiple
                                        size="3"
                                        class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                                    <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= in_array($u['id'], $assignViewUsers) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['username']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Groupes :</label>
                                <select name="actions[<?= $index ?>][assign_view_groups][]" 
                                        multiple
                                        size="3"
                                        class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                                    <?php foreach ($groups as $g): ?>
                                    <option value="<?= $g['id'] ?>" <?= in_array($g['id'], $assignViewGroups) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($g['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Assigner des autorisations d'édition</h4>
                        <div class="space-y-2">
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Utilisateurs :</label>
                                <select name="actions[<?= $index ?>][assign_change_users][]" 
                                        multiple
                                        size="3"
                                        class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                                    <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= in_array($u['id'], $assignChangeUsers) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['username']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Groupes :</label>
                                <select name="actions[<?= $index ?>][assign_change_groups][]" 
                                        multiple
                                        size="3"
                                        class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                                    <?php foreach ($groups as $g): ?>
                                    <option value="<?= $g['id'] ?>" <?= in_array($g['id'], $assignChangeGroups) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($g['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Modifier les droits d'accès accorde également les droits de lecture</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Removal (2) -->
        <div class="removal-fields" style="display: <?= $actionType == 2 ? 'block' : 'none' ?>;">
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-3">
                    <div>
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Supprimer des étiquettes</h4>
                        <label class="flex items-center mb-2">
                            <input type="checkbox" 
                                   name="actions[<?= $index ?>][remove_all_tags]" 
                                   value="1"
                                   <?= (isset($action['remove_all_tags']) && $action['remove_all_tags']) ? 'checked' : '' ?>
                                   class="mr-2">
                            <span class="text-sm">Supprimer toutes</span>
                        </label>
                        <select name="actions[<?= $index ?>][remove_tags][]" 
                                multiple
                                size="5"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            <?php foreach ($tags as $tag): ?>
                            <option value="<?= $tag['id'] ?>" <?= in_array($tag['id'], $removeTags) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tag['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Supprimer des correspondants</h4>
                        <label class="flex items-center mb-2">
                            <input type="checkbox" 
                                   name="actions[<?= $index ?>][remove_all_correspondents]" 
                                   value="1"
                                   <?= (isset($action['remove_all_correspondents']) && $action['remove_all_correspondents']) ? 'checked' : '' ?>
                                   class="mr-2">
                            <span class="text-sm">Supprimer tous</span>
                        </label>
                        <select name="actions[<?= $index ?>][remove_correspondents][]" 
                                multiple
                                size="5"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            <?php foreach ($correspondents as $corr): ?>
                            <option value="<?= $corr['id'] ?>" <?= in_array($corr['id'], $removeCorrespondents) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($corr['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Supprimer des types de documents</h4>
                        <label class="flex items-center mb-2">
                            <input type="checkbox" 
                                   name="actions[<?= $index ?>][remove_all_document_types]" 
                                   value="1"
                                   <?= (isset($action['remove_all_document_types']) && $action['remove_all_document_types']) ? 'checked' : '' ?>
                                   class="mr-2">
                            <span class="text-sm">Supprimer tous</span>
                        </label>
                        <select name="actions[<?= $index ?>][remove_document_types][]" 
                                multiple
                                size="5"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            <?php foreach ($documentTypes as $dt): ?>
                            <option value="<?= $dt['id'] ?>" <?= in_array($dt['id'], $removeDocumentTypes) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dt['label']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Supprimer des chemins de stockage</h4>
                        <label class="flex items-center mb-2">
                            <input type="checkbox" 
                                   name="actions[<?= $index ?>][remove_all_storage_paths]" 
                                   value="1"
                                   <?= (isset($action['remove_all_storage_paths']) && $action['remove_all_storage_paths']) ? 'checked' : '' ?>
                                   class="mr-2">
                            <span class="text-sm">Supprimer tous</span>
                        </label>
                        <select name="actions[<?= $index ?>][remove_storage_paths][]" 
                                multiple
                                size="5"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            <?php foreach ($storagePaths as $sp): ?>
                            <option value="<?= $sp['id'] ?>" <?= in_array($sp['id'], $removeStoragePaths) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sp['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Supprimer des champs personnalisés</h4>
                        <label class="flex items-center mb-2">
                            <input type="checkbox" 
                                   name="actions[<?= $index ?>][remove_all_custom_fields]" 
                                   value="1"
                                   <?= (isset($action['remove_all_custom_fields']) && $action['remove_all_custom_fields']) ? 'checked' : '' ?>
                                   class="mr-2">
                            <span class="text-sm">Supprimer tous</span>
                        </label>
                        <select name="actions[<?= $index ?>][remove_custom_fields][]" 
                                multiple
                                size="5"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            <?php foreach ($customFields as $cf): ?>
                            <option value="<?= $cf['id'] ?>" <?= in_array($cf['id'], $removeCustomFields) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cf['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="space-y-3">
                    <div>
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Supprimer des propriétaires</h4>
                        <label class="flex items-center mb-2">
                            <input type="checkbox" 
                                   name="actions[<?= $index ?>][remove_all_owners]" 
                                   value="1"
                                   <?= (isset($action['remove_all_owners']) && $action['remove_all_owners']) ? 'checked' : '' ?>
                                   class="mr-2">
                            <span class="text-sm">Supprimer tous</span>
                        </label>
                        <select name="actions[<?= $index ?>][remove_owners][]" 
                                multiple
                                size="5"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= in_array($u['id'], $removeOwners) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['username']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Supprimer des permissions</h4>
                        <label class="flex items-center mb-2">
                            <input type="checkbox" 
                                   name="actions[<?= $index ?>][remove_all_permissions]" 
                                   value="1"
                                   <?= (isset($action['remove_all_permissions']) && $action['remove_all_permissions']) ? 'checked' : '' ?>
                                   class="mr-2">
                            <span class="text-sm">Supprimer toutes</span>
                        </label>
                        
                        <div class="space-y-2 mt-2">
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Permissions de vue - Utilisateurs :</label>
                                <select name="actions[<?= $index ?>][remove_view_users][]" 
                                        multiple
                                        size="3"
                                        class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                                    <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= in_array($u['id'], $removeViewUsers) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['username']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Permissions de vue - Groupes :</label>
                                <select name="actions[<?= $index ?>][remove_view_groups][]" 
                                        multiple
                                        size="3"
                                        class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                                    <?php foreach ($groups as $g): ?>
                                    <option value="<?= $g['id'] ?>" <?= in_array($g['id'], $removeViewGroups) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($g['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Permissions d'édition - Utilisateurs :</label>
                                <select name="actions[<?= $index ?>][remove_change_users][]" 
                                        multiple
                                        size="3"
                                        class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                                    <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= in_array($u['id'], $removeChangeUsers) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['username']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Permissions d'édition - Groupes :</label>
                                <select name="actions[<?= $index ?>][remove_change_groups][]" 
                                        multiple
                                        size="3"
                                        class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                                    <?php foreach ($groups as $g): ?>
                                    <option value="<?= $g['id'] ?>" <?= in_array($g['id'], $removeChangeGroups) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($g['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Email (3) -->
        <div class="email-fields" style="display: <?= $actionType == 3 ? 'block' : 'none' ?>;">
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sujet de l'email</label>
                    <input type="text" 
                           name="actions[<?= $index ?>][email_subject]" 
                           value="<?= htmlspecialchars(isset($action['email_subject']) ? $action['email_subject'] : '') ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Corps de l'email</label>
                    <textarea name="actions[<?= $index ?>][email_body]" 
                              rows="5"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100"><?= htmlspecialchars(isset($action['email_body']) ? $action['email_body'] : '') ?></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Destinataires</label>
                    <input type="text" 
                           name="actions[<?= $index ?>][email_to]" 
                           value="<?= htmlspecialchars(isset($action['email_to']) ? $action['email_to'] : '') ?>"
                           placeholder="email@example.com"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                </div>
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" 
                               name="actions[<?= $index ?>][email_include_document]" 
                               value="1"
                               <?= (isset($action['email_include_document']) && $action['email_include_document']) ? 'checked' : '' ?>
                               class="mr-2">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Joindre le document</span>
                    </label>
                </div>
            </div>
        </div>
        
        <!-- Webhook (4) -->
        <div class="webhook-fields" style="display: <?= $actionType == 4 ? 'block' : 'none' ?>;">
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">URL du webhook</label>
                    <input type="url" 
                           name="actions[<?= $index ?>][webhook_url]" 
                           value="<?= htmlspecialchars(isset($action['webhook_url']) ? $action['webhook_url'] : '') ?>"
                           placeholder="https://example.com/webhook"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" 
                                   name="actions[<?= $index ?>][webhook_use_params]" 
                                   value="1"
                                   <?= (isset($action['webhook_use_params']) && $action['webhook_use_params']) ? 'checked' : '' ?>
                                   class="mr-2"
                                   onchange="toggleWebhookParams(this)">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Utiliser des paramètres pour le corps</span>
                        </label>
                    </div>
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" 
                                   name="actions[<?= $index ?>][webhook_as_json]" 
                                   value="1"
                                   <?= (!isset($action['webhook_as_json']) || $action['webhook_as_json']) ? 'checked' : '' ?>
                                   class="mr-2">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Envoyer en JSON</span>
                        </label>
                    </div>
                </div>
                <div id="webhook-params-<?= $index ?>" style="display: <?= (isset($action['webhook_use_params']) && $action['webhook_use_params']) ? 'block' : 'none' ?>;">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Paramètres (JSON)</label>
                    <textarea name="actions[<?= $index ?>][webhook_params]" 
                              rows="3"
                              placeholder='{"key": "value"}'
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100"><?= htmlspecialchars(isset($action['webhook_params']) ? $action['webhook_params'] : '') ?></textarea>
                </div>
                <div id="webhook-body-<?= $index ?>" style="display: <?= (isset($action['webhook_use_params']) && $action['webhook_use_params']) ? 'none' : 'block' ?>;">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Corps du webhook</label>
                    <textarea name="actions[<?= $index ?>][webhook_body]" 
                              rows="5"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100"><?= htmlspecialchars(isset($action['webhook_body']) ? $action['webhook_body'] : '') ?></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">En-têtes (JSON)</label>
                    <textarea name="actions[<?= $index ?>][webhook_headers]" 
                              rows="3"
                              placeholder='{"Authorization": "Bearer token"}'
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100"><?= htmlspecialchars(isset($action['webhook_headers']) ? $action['webhook_headers'] : '') ?></textarea>
                </div>
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" 
                               name="actions[<?= $index ?>][webhook_include_document]" 
                               value="1"
                               <?= (isset($action['webhook_include_document']) && $action['webhook_include_document']) ? 'checked' : '' ?>
                               class="mr-2">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Inclure le document</span>
                    </label>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleWebhookParams(checkbox) {
    const index = checkbox.closest('.action-item').dataset.actionIndex;
    const paramsDiv = document.getElementById('webhook-params-' + index);
    const bodyDiv = document.getElementById('webhook-body-' + index);
    
    if (checkbox.checked) {
        paramsDiv.style.display = 'block';
        bodyDiv.style.display = 'none';
    } else {
        paramsDiv.style.display = 'none';
        bodyDiv.style.display = 'block';
    }
}
</script>
