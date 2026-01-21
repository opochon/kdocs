<?php
// Page de configuration des paramètres système
use KDocs\Core\Config;
use KDocs\Models\Setting;
$base = Config::basePath();

// Valeurs actuelles (depuis DB ou config par défaut)
// Utiliser Config::get qui charge automatiquement depuis DB avec fallback sur config
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
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">🤖 Intelligence Artificielle</h2>
            
            <div class="space-y-4">
                <div>
                    <label for="ai_claude_api_key" class="block text-sm font-medium text-gray-700 mb-2">
                        Clé API Claude
                    </label>
                    <input type="password" 
                           id="ai_claude_api_key" 
                           name="ai[claude_api_key]" 
                           value="<?= htmlspecialchars($claudeApiKey) ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="sk-ant-api03-...">
                    <p class="mt-1 text-sm text-gray-500">
                        Clé API Claude pour l'extraction intelligente de métadonnées. Laissez vide pour utiliser le fichier claude_api_key.txt.
                    </p>
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
