<?php
// Formulaire de création/édition de type de document (comme Paperless-ngx)
use KDocs\Core\Database;
use KDocs\Core\Config;

$db = Database::getInstance();

// Récupérer les utilisateurs et groupes pour les permissions
$users = $db->query("SELECT id, username, first_name, last_name FROM users ORDER BY username")->fetchAll();
$groups = $db->query("SELECT id, name FROM groups ORDER BY name")->fetchAll();

// Décoder les permissions JSON si elles existent
$viewUsers = $documentType ? json_decode($documentType['view_users'] ?? '[]', true) : [];
$viewGroups = $documentType ? json_decode($documentType['view_groups'] ?? '[]', true) : [];
$modifyUsers = $documentType ? json_decode($documentType['modify_users'] ?? '[]', true) : [];
$modifyGroups = $documentType ? json_decode($documentType['modify_groups'] ?? '[]', true) : [];

// Algorithme de matching (valeurs numériques comme Paperless)
$matchingAlgorithms = [
    6 => 'Automatique : apprentissage automatique du rapprochement',
    1 => 'Any : Document contient n\'importe lequel de ces mots (séparés par des espaces)',
    2 => 'All : Document contient tous ces mots (séparés par des espaces)',
    3 => 'Exact : Document contient cette chaîne exacte',
    4 => 'Expression régulière : Document correspond à cette expression régulière',
    5 => 'Fuzzy : Document contient un mot similaire à ce mot',
    0 => 'Aucun : Désactiver le rapprochement'
];

$currentMatchingAlgorithm = $documentType['matching_algorithm'] ?? 6;
$patternRequired = in_array($currentMatchingAlgorithm, [1, 2, 3, 4, 5]);
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">
            <?= $documentType ? 'Modifier le type de document' : 'Créer un type de document' ?>
            <?php if ($documentType): ?>
            <span class="ml-2 text-sm font-normal text-gray-500">ID: <?= $documentType['id'] ?></span>
            <?php endif; ?>
        </h1>
        <a href="<?= url('/admin/document-types') ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
            ← Retour
        </a>
    </div>

    <form method="POST" action="<?= url($documentType ? '/admin/document-types/' . $documentType['id'] . '/save' : '/admin/document-types/save') ?>" 
          class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 space-y-6">
        
        <?php if ($documentType): ?>
        <input type="hidden" name="id" value="<?= $documentType['id'] ?>">
        <?php endif; ?>

        <!-- Nom -->
        <div>
            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom *</label>
            <input type="text" 
                   id="name" 
                   name="name" 
                   value="<?= htmlspecialchars($documentType['label'] ?? $documentType['code'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                   required>
        </div>

        <!-- Algorithme de rapprochement -->
        <div>
            <label for="matching_algorithm" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Algorithme de rapprochement</label>
            <select id="matching_algorithm" 
                    name="matching_algorithm"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                    onchange="togglePatternFields()">
                <?php foreach ($matchingAlgorithms as $value => $label): ?>
                <option value="<?= $value ?>" <?= ($currentMatchingAlgorithm == $value) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Pattern de matching (affiché si nécessaire) -->
        <div id="pattern-fields" style="display: <?= $patternRequired ? 'block' : 'none' ?>;">
            <div>
                <label for="match" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Modèle de rapprochement</label>
                <input type="text" 
                       id="match" 
                       name="match" 
                       value="<?= htmlspecialchars($documentType['match'] ?? '') ?>"
                       placeholder="ex: facture"
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
            </div>
            
            <div class="mt-3">
                <label class="flex items-center">
                    <input type="checkbox" 
                           name="is_insensitive" 
                           value="1"
                           <?= ($documentType['is_insensitive'] ?? true) ? 'checked' : '' ?>
                           class="mr-2">
                    <span class="text-sm text-gray-700 dark:text-gray-300">Insensible à la casse</span>
                </label>
            </div>
        </div>

        <!-- Autorisations de modification -->
        <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Autorisations de modification</h2>
                <button type="button" onclick="togglePermissions()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-chevron-up" id="permissions-icon"></i>
                </button>
            </div>
            
            <div id="permissions-content" class="space-y-4">
                <!-- Propriétaire -->
                <div>
                    <label for="owner_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Propriétaire</label>
                    <select id="owner_id" 
                            name="owner_id"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">Aucun</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ($documentType['owner_id'] ?? null) == $u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['username'] . ($u['first_name'] || $u['last_name'] ? ' (' . trim($u['first_name'] . ' ' . $u['last_name']) . ')' : '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Les objets sans propriétaire peuvent être consultés et édités par tous les utilisateurs</p>
                </div>

                <!-- Vue -->
                <div>
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Vue</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="view_users" class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Utilisateurs</label>
                            <select id="view_users" 
                                    name="view_users[]"
                                    multiple
                                    size="5"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= in_array($u['id'], $viewUsers) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['username']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Ctrl+clic pour sélectionner plusieurs</p>
                        </div>
                        <div>
                            <label for="view_groups" class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Groupes</label>
                            <select id="view_groups" 
                                    name="view_groups[]"
                                    multiple
                                    size="5"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                                <?php foreach ($groups as $g): ?>
                                <option value="<?= $g['id'] ?>" <?= in_array($g['id'], $viewGroups) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($g['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Ctrl+clic pour sélectionner plusieurs</p>
                        </div>
                    </div>
                </div>

                <!-- Modifier -->
                <div>
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Modifier</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="modify_users" class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Utilisateurs</label>
                            <select id="modify_users" 
                                    name="modify_users[]"
                                    multiple
                                    size="5"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= in_array($u['id'], $modifyUsers) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['username']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Ctrl+clic pour sélectionner plusieurs</p>
                        </div>
                        <div>
                            <label for="modify_groups" class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Groupes</label>
                            <select id="modify_groups" 
                                    name="modify_groups[]"
                                    multiple
                                    size="5"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                                <?php foreach ($groups as $g): ?>
                                <option value="<?= $g['id'] ?>" <?= in_array($g['id'], $modifyGroups) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($g['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Ctrl+clic pour sélectionner plusieurs</p>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Modifier les droits d'accès accorde également les droits de lecture</p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between pt-6 border-t border-gray-200 dark:border-gray-700">
            <a href="<?= url('/admin/document-types') ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
                Annuler
            </a>
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                Enregistrer
            </button>
        </div>
    </form>
</div>

<script>
function togglePatternFields() {
    const algorithm = document.getElementById('matching_algorithm').value;
    const patternFields = document.getElementById('pattern-fields');
    const requiredAlgorithms = ['1', '2', '3', '4', '5']; // Any, All, Literal, Regex, Fuzzy
    
    if (requiredAlgorithms.includes(algorithm)) {
        patternFields.style.display = 'block';
    } else {
        patternFields.style.display = 'none';
    }
}

let permissionsExpanded = true;
function togglePermissions() {
    const content = document.getElementById('permissions-content');
    const icon = document.getElementById('permissions-icon');
    
    permissionsExpanded = !permissionsExpanded;
    content.style.display = permissionsExpanded ? 'block' : 'none';
    icon.className = permissionsExpanded ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
}
</script>
