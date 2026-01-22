<?php
// Page de configuration des paramètres système
use KDocs\Core\Config;
use KDocs\Models\Setting;
$base = Config::basePath();

// Valeurs actuelles (depuis DB ou config par défaut)
// Utiliser Config::get qui charge automatiquement depuis DB avec fallback sur config
$defaultConfig = Config::load();
$basePath = Config::get('storage.base_path', '');
if (empty($basePath)) {
    $basePath = $defaultConfig['storage']['base_path'] ?? '';
}
// Résoudre le chemin relatif en chemin absolu si nécessaire
if (!empty($basePath)) {
    $resolved = realpath($basePath);
    if ($resolved) {
        $basePath = $resolved;
    }
}

$allowedExtensions = Setting::get('storage.allowed_extensions', '');
if (empty($allowedExtensions)) {
    $allowedExtensions = implode(',', $defaultConfig['storage']['allowed_extensions'] ?? []);
}

$tesseractPath = Setting::get('ocr.tesseract_path', '');
if (empty($tesseractPath)) {
    $tesseractPath = $defaultConfig['ocr']['tesseract_path'] ?? '';
}

$claudeApiKey = Setting::get('ai.claude_api_key', '');
if (empty($claudeApiKey)) {
    $claudeApiKey = $defaultConfig['ai']['claude_api_key'] ?? '';
}

// Configuration KDrive
$storageType = Setting::get('storage.type', 'local');
$kdriveDriveId = Setting::get('kdrive.drive_id', '');
$kdriveUsername = Setting::get('kdrive.username', '');
$kdrivePassword = Setting::get('kdrive.password', '');
$kdriveBasePath = Setting::get('kdrive.base_path', '');

// Messages
$successMsg = $_GET['success'] ?? null;
$errorMsg = $_GET['error'] ?? null;
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">⚙️ Paramètres système</h1>
    </div>

    <?php if ($successMsg): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
        ✅ <?= htmlspecialchars($successMsg) ?>
    </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">
        ❌ <?= htmlspecialchars($errorMsg) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/admin/settings/save') ?>" class="space-y-6">
        
        <!-- Section Stockage -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">📁 Stockage</h2>
            
            <div class="space-y-4">
                <div>
                    <label for="storage_type" class="block text-sm font-medium text-gray-700 mb-2">
                        Type de stockage
                    </label>
                    <select id="storage_type" 
                            name="storage[type]" 
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            onchange="toggleStorageType()">
                        <option value="local" <?= $storageType === 'local' ? 'selected' : '' ?>>Local (Filesystem)</option>
                        <option value="kdrive" <?= $storageType === 'kdrive' ? 'selected' : '' ?>>KDrive (Infomaniak)</option>
                    </select>
                    <p class="mt-1 text-sm text-gray-500">
                        Choisissez le type de stockage pour vos documents.
                    </p>
                </div>
                
                <!-- Configuration Local -->
                <div id="storage-local-config">
                    <div>
                        <label for="storage_base_path" class="block text-sm font-medium text-gray-700 mb-2">
                            Racine des documents (base_path)
                        </label>
                        <input type="text" 
                               id="storage_base_path" 
                               name="storage[base_path]" 
                               value="<?= htmlspecialchars($basePath) ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="C:\wamp64\www\kdocs\storage\documents">
                        <p class="mt-1 text-sm text-gray-500">
                            Chemin racine où sont stockés les documents. Laissez vide pour utiliser la valeur par défaut.
                        </p>
                        <?php if ($basePath && is_dir($basePath)): ?>
                        <p class="mt-1 text-sm text-green-600">✅ Le dossier existe</p>
                        <?php elseif ($basePath): ?>
                        <p class="mt-1 text-sm text-red-600">❌ Le dossier n'existe pas</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Configuration KDrive -->
                <div id="storage-kdrive-config" style="display: <?= $storageType === 'kdrive' ? 'block' : 'none' ?>;">
                    <div class="space-y-4">
                        <div>
                            <label for="kdrive_drive_id" class="block text-sm font-medium text-gray-700 mb-2">
                                Drive ID
                            </label>
                            <input type="text" 
                                   id="kdrive_drive_id" 
                                   name="kdrive[drive_id]" 
                                   value="<?= htmlspecialchars($kdriveDriveId) ?>"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="123456">
                            <p class="mt-1 text-sm text-gray-500">
                                ID du Drive KDrive (extrait de l'URL : /drive/123456/). Trouvez-le dans l'URL de votre kDrive.
                            </p>
                        </div>
                        
                        <div>
                            <label for="kdrive_username" class="block text-sm font-medium text-gray-700 mb-2">
                                Email Infomaniak
                            </label>
                            <input type="email" 
                                   id="kdrive_username" 
                                   name="kdrive[username]" 
                                   value="<?= htmlspecialchars($kdriveUsername) ?>"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="votre@email.infomaniak.com">
                            <p class="mt-1 text-sm text-gray-500">
                                Email de votre compte Infomaniak.
                            </p>
                        </div>
                        
                        <div>
                            <label for="kdrive_password" class="block text-sm font-medium text-gray-700 mb-2">
                                Mot de passe d'application
                            </label>
                            <input type="password" 
                                   id="kdrive_password" 
                                   name="kdrive[password]" 
                                   value="<?= htmlspecialchars($kdrivePassword) ?>"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="Mot de passe d'application">
                            <p class="mt-1 text-sm text-gray-500">
                                Si vous avez activé l'authentification à deux facteurs, créez un mot de passe d'application dans les paramètres Infomaniak.
                            </p>
                        </div>
                        
                        <div>
                            <label for="kdrive_base_path" class="block text-sm font-medium text-gray-700 mb-2">
                                Chemin de base dans KDrive (optionnel)
                            </label>
                            <input type="text" 
                                   id="kdrive_base_path" 
                                   name="kdrive[base_path]" 
                                   value="<?= htmlspecialchars($kdriveBasePath) ?>"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="Documents/K-Docs">
                            <p class="mt-1 text-sm text-gray-500">
                                Dossier de base dans KDrive (laissez vide pour utiliser la racine du Drive).
                            </p>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label for="storage_allowed_extensions" class="block text-sm font-medium text-gray-700 mb-2">
                        Extensions autorisées
                    </label>
                    <input type="text" 
                           id="storage_allowed_extensions" 
                           name="storage[allowed_extensions]" 
                           value="<?= htmlspecialchars($allowedExtensions) ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="pdf,png,jpg,jpeg,tiff,doc,docx">
                    <p class="mt-1 text-sm text-gray-500">
                        Liste des extensions autorisées, séparées par des virgules.
                    </p>
                </div>
            </div>
        </div>
        
        <script>
        function toggleStorageType() {
            const type = document.getElementById('storage_type').value;
            document.getElementById('storage-local-config').style.display = type === 'local' ? 'block' : 'none';
            document.getElementById('storage-kdrive-config').style.display = type === 'kdrive' ? 'block' : 'none';
        }
        
        // Initialiser l'affichage au chargement de la page
        document.addEventListener('DOMContentLoaded', function() {
            toggleStorageType();
        });
        </script>

        <!-- Section OCR -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">🔍 OCR</h2>
            
            <div class="space-y-4">
                <div>
                    <label for="ocr_tesseract_path" class="block text-sm font-medium text-gray-700 mb-2">
                        Chemin vers Tesseract
                    </label>
                    <input type="text" 
                           id="ocr_tesseract_path" 
                           name="ocr[tesseract_path]" 
                           value="<?= htmlspecialchars($tesseractPath) ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="C:\Program Files\Tesseract-OCR\tesseract.exe">
                    <p class="mt-1 text-sm text-gray-500">
                        Chemin complet vers l'exécutable Tesseract. Laissez vide pour utiliser la valeur par défaut.
                    </p>
                    <?php if ($tesseractPath && file_exists($tesseractPath)): ?>
                    <p class="mt-1 text-sm text-green-600">✅ L'exécutable existe</p>
                    <?php elseif ($tesseractPath): ?>
                    <p class="mt-1 text-sm text-red-600">❌ L'exécutable n'existe pas</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Section AI -->
        <div id="ai" class="bg-white rounded-lg shadow p-6 border-2 border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-gray-800">🤖 Intelligence Artificielle (Claude API)</h2>
                <?php if (empty($claudeApiKey)): ?>
                <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded">Non configuré</span>
                <?php else: ?>
                <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">Configuré</span>
                <?php endif; ?>
            </div>
            
            <div class="space-y-4">
                <div>
                    <label for="ai_claude_api_key" class="block text-sm font-medium text-gray-700 mb-2">
                        Clé API Claude <span class="text-red-500">*</span>
                    </label>
                    <input type="password" 
                           id="ai_claude_api_key" 
                           name="ai[claude_api_key]" 
                           value="<?= htmlspecialchars($claudeApiKey ?? '') ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="sk-ant-api03-...">
                    <p class="mt-1 text-sm text-gray-500">
                        Clé API Claude pour l'extraction intelligente de métadonnées et le chat IA. 
                        <a href="https://console.anthropic.com/" target="_blank" class="text-blue-600 hover:underline">Obtenir une clé API</a>
                    </p>
                    <?php if (!empty($claudeApiKey)): ?>
                    <p class="mt-1 text-sm text-green-600">✅ Clé API configurée (masquée pour sécurité)</p>
                    <?php else: ?>
                    <p class="mt-1 text-sm text-yellow-600">⚠️ La clé API est requise pour utiliser le Chat IA</p>
                    <?php endif; ?>
                </div>
                
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h3 class="text-sm font-medium text-blue-900 mb-2">Utilisation</h3>
                    <ul class="text-xs text-blue-800 space-y-1 list-disc list-inside">
                        <li>Classification automatique des documents</li>
                        <li>Extraction intelligente de métadonnées</li>
                        <li>Chat IA pour interroger vos documents</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Boutons -->
        <div class="flex items-center justify-end gap-4">
            <a href="<?= url('/admin') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Annuler
            </a>
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                💾 Enregistrer les paramètres
            </button>
        </div>
    </form>
</div>
