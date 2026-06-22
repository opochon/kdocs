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

// Configuration Indexation
$indexingMaxQueues = Setting::get('indexing_max_concurrent_queues', '2');
$indexingMemoryLimit = Setting::get('indexing_memory_limit', '128');
$indexingDelayFiles = Setting::get('indexing_delay_between_files', '50');
$indexingBatchSize = Setting::get('indexing_batch_size', '20');
$indexingBatchPause = Setting::get('indexing_batch_pause', '500');
$indexingTurboMode = Setting::get('indexing_turbo_mode', '0');

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

        <!-- Section Outils & Services (statut uniquement) -->
        <?php
        // Récupérer les chemins depuis config
        $toolsConfig = $defaultConfig['tools'] ?? [];
        $ghostscriptPath = $toolsConfig['ghostscript'] ?? '';
        $pdftotextPath = $toolsConfig['pdftotext'] ?? '';
        $pdftoppmPath = $toolsConfig['pdftoppm'] ?? '';
        $libreofficePath = $toolsConfig['libreoffice'] ?? '';

        // OnlyOffice config
        $onlyofficeConfig = $defaultConfig['onlyoffice'] ?? [];
        $onlyofficeEnabled = $onlyofficeConfig['enabled'] ?? false;
        $onlyofficeUrl = $onlyofficeConfig['server_url'] ?? 'http://localhost:8080';

        // Embeddings config
        $embeddingsConfig = $defaultConfig['embeddings'] ?? [];
        $embeddingsEnabled = $embeddingsConfig['enabled'] ?? false;
        $ollamaUrl = $embeddingsConfig['ollama_url'] ?? 'http://localhost:11434';
        $ollamaModel = $embeddingsConfig['ollama_model'] ?? 'nomic-embed-text';

        // Qdrant config
        $qdrantConfig = $defaultConfig['qdrant'] ?? [];
        $qdrantHost = $qdrantConfig['host'] ?? 'localhost';
        $qdrantPort = $qdrantConfig['port'] ?? 6333;

        // Fonction helper pour tester les services HTTP
        function testHttpService(string $url, int $timeout = 2): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_NOBODY => false,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'ok' => $httpCode >= 200 && $httpCode < 400,
                'code' => $httpCode,
                'response' => $response,
                'error' => $error
            ];
        }

        // Test OnlyOffice
        $onlyofficeStatus = ['ok' => false, 'message' => 'Non testé'];
        if ($onlyofficeEnabled) {
            $healthUrl = rtrim($onlyofficeUrl, '/') . '/healthcheck';
            $result = testHttpService($healthUrl);
            if ($result['ok'] && trim($result['response']) === 'true') {
                $onlyofficeStatus = ['ok' => true, 'message' => 'Serveur en ligne'];
            } elseif ($result['code'] > 0) {
                $onlyofficeStatus = ['ok' => false, 'message' => "HTTP {$result['code']}"];
            } else {
                $onlyofficeStatus = ['ok' => false, 'message' => $result['error'] ?: 'Connexion échouée'];
            }
        }

        // Test Ollama
        $ollamaStatus = ['ok' => false, 'message' => 'Non testé', 'models' => []];
        if ($embeddingsEnabled) {
            $result = testHttpService($ollamaUrl . '/api/tags');
            if ($result['ok']) {
                $data = json_decode($result['response'], true);
                $models = array_column($data['models'] ?? [], 'name');
                $hasModel = in_array($ollamaModel, $models) || in_array($ollamaModel . ':latest', $models);
                $ollamaStatus = [
                    'ok' => $hasModel,
                    'message' => $hasModel ? 'Modèle disponible' : "Modèle '$ollamaModel' non trouvé",
                    'models' => $models
                ];
            } else {
                $ollamaStatus = ['ok' => false, 'message' => $result['error'] ?: 'Connexion échouée', 'models' => []];
            }
        }

        // Test Qdrant
        $qdrantStatus = ['ok' => false, 'message' => 'Non testé', 'collections' => 0];
        if ($embeddingsEnabled) {
            $qdrantUrl = "http://{$qdrantHost}:{$qdrantPort}/collections";
            $result = testHttpService($qdrantUrl);
            if ($result['ok']) {
                $data = json_decode($result['response'], true);
                $collections = $data['result']['collections'] ?? [];
                $qdrantStatus = [
                    'ok' => true,
                    'message' => count($collections) . ' collection(s)',
                    'collections' => count($collections)
                ];
            } else {
                $qdrantStatus = ['ok' => false, 'message' => $result['error'] ?: 'Connexion échouée', 'collections' => 0];
            }
        }
        ?>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">🔧 Outils système</h2>
            <p class="text-sm text-gray-500 mb-4">Statut des outils externes utilisés par K-Docs. Les chemins sont configurés dans <code>config/config.php</code>.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Tesseract OCR -->
                <div class="border rounded-lg p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium text-gray-700">Tesseract OCR</span>
                        <?php if ($tesseractPath && file_exists($tesseractPath)): ?>
                        <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">✅ Disponible</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">❌ Non trouvé</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 truncate" title="<?= htmlspecialchars($tesseractPath) ?>">
                        <?= htmlspecialchars($tesseractPath ?: 'Non configuré') ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">Extraction de texte des images et PDFs scannés</p>
                </div>

                <!-- Ghostscript -->
                <div class="border rounded-lg p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium text-gray-700">Ghostscript</span>
                        <?php if ($ghostscriptPath && file_exists($ghostscriptPath)): ?>
                        <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">✅ Disponible</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">❌ Non trouvé</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 truncate" title="<?= htmlspecialchars($ghostscriptPath) ?>">
                        <?= htmlspecialchars($ghostscriptPath ?: 'Non configuré') ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">Conversion et rendu PDF, génération de miniatures</p>
                </div>

                <!-- pdftotext -->
                <div class="border rounded-lg p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium text-gray-700">pdftotext (Poppler)</span>
                        <?php if ($pdftotextPath && file_exists($pdftotextPath)): ?>
                        <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">✅ Disponible</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">❌ Non trouvé</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 truncate" title="<?= htmlspecialchars($pdftotextPath) ?>">
                        <?= htmlspecialchars($pdftotextPath ?: 'Non configuré') ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">Extraction de texte natif des PDFs</p>
                </div>

                <!-- pdftoppm -->
                <div class="border rounded-lg p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium text-gray-700">pdftoppm (Poppler)</span>
                        <?php if ($pdftoppmPath && file_exists($pdftoppmPath)): ?>
                        <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">✅ Disponible</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">❌ Non trouvé</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 truncate" title="<?= htmlspecialchars($pdftoppmPath) ?>">
                        <?= htmlspecialchars($pdftoppmPath ?: 'Non configuré') ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">Conversion PDF vers images (miniatures)</p>
                </div>

                <!-- LibreOffice -->
                <?php
                // Version récupérée en cache pour éviter le blocage
                $libreofficeVersion = '';
                $libreversionCache = sys_get_temp_dir() . '/kdocs_libreoffice_version.txt';
                if ($libreofficePath && file_exists($libreofficePath)) {
                    // Utiliser le cache si < 1 heure
                    if (file_exists($libreversionCache) && (time() - filemtime($libreversionCache)) < 3600) {
                        $libreofficeVersion = trim(file_get_contents($libreversionCache));
                    }
                    // Sinon on affiche juste "Disponible" sans bloquer
                }
                ?>
                <div class="border rounded-lg p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium text-gray-700">LibreOffice</span>
                        <?php if ($libreofficePath && file_exists($libreofficePath)): ?>
                        <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">✅ <?= $libreofficeVersion ? 'v' . htmlspecialchars($libreofficeVersion) : 'Disponible' ?></span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded">⚠️ Non trouvé</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 truncate" title="<?= htmlspecialchars($libreofficePath) ?>">
                        <?= htmlspecialchars($libreofficePath ?: 'Non configuré') ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">Conversion DOCX, XLSX, PPTX → PDF/miniatures</p>
                </div>

            </div>
        </div>

        <!-- Section OnlyOffice -->
        <?php
        $onlyofficeSslVerify = $onlyofficeConfig['ssl_verify'] ?? false;
        $onlyofficeDebugLog = $onlyofficeConfig['debug_log'] ?? false;
        $onlyofficeCallbackUrl = $onlyofficeConfig['callback_url'] ?? '';
        ?>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-gray-800">📄 OnlyOffice Document Server</h2>
                <?php if (!$onlyofficeEnabled): ?>
                <span class="px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded">Désactivé</span>
                <?php elseif ($onlyofficeStatus['ok']): ?>
                <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">✅ <?= htmlspecialchars($onlyofficeStatus['message']) ?></span>
                <?php else: ?>
                <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">❌ <?= htmlspecialchars($onlyofficeStatus['message']) ?></span>
                <?php endif; ?>
            </div>

            <div class="space-y-4">
                <!-- Statut actuel -->
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-600">URL du serveur</span>
                        <code class="text-xs bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($onlyofficeUrl) ?></code>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-600">JWT Secret</span>
                        <span class="text-xs <?= empty($onlyofficeConfig['jwt_secret']) ? 'text-yellow-600' : 'text-green-600' ?>">
                            <?= empty($onlyofficeConfig['jwt_secret']) ? '⚠️ Non configuré' : '✅ Configuré' ?>
                        </span>
                    </div>
                </div>

                <!-- Configuration Callback URL -->
                <div class="border-t pt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Callback URL (pour Docker)
                    </label>
                    <input type="text"
                           id="onlyoffice_callback_url"
                           name="onlyoffice[callback_url]"
                           value="<?= htmlspecialchars($onlyofficeCallbackUrl) ?>"
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="http://192.168.1.x/kdocs ou http://host.docker.internal/kdocs">
                    <p class="mt-1 text-xs text-gray-500">
                        URL que le container Docker OnlyOffice utilise pour atteindre K-Docs.
                        <strong>Ne pas utiliser localhost</strong> - utilisez votre IP locale ou <code>host.docker.internal</code>.
                    </p>
                </div>

                <!-- Options -->
                <div class="grid grid-cols-2 gap-4 border-t pt-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="onlyoffice[ssl_verify]" value="1"
                               <?= $onlyofficeSslVerify ? 'checked' : '' ?>
                               class="mr-2 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm text-gray-700">Vérification SSL</span>
                    </label>
                    <label class="flex items-center">
                        <input type="checkbox" name="onlyoffice[debug_log]" value="1"
                               <?= $onlyofficeDebugLog ? 'checked' : '' ?>
                               class="mr-2 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm text-gray-700">Logs détaillés</span>
                    </label>
                </div>
                <p class="text-xs text-gray-500 -mt-2">
                    ⚠️ Désactivez la vérification SSL en développement si vous avez des erreurs de certificat.
                    Logs stockés dans <code>storage/logs/onlyoffice.log</code>
                </p>

                <!-- Boutons de diagnostic -->
                <div class="flex gap-3 border-t pt-4">
                    <button type="button"
                            id="btn-test-onlyoffice"
                            onclick="testOnlyOfficeConnectivity()"
                            class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm">
                        🔍 Tester la connectivité
                    </button>
                    <button type="button"
                            id="btn-view-onlyoffice-logs"
                            onclick="toggleOnlyOfficeLogs()"
                            class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 text-sm">
                        📋 Voir les logs
                    </button>
                    <button type="button"
                            id="btn-clear-onlyoffice-logs"
                            onclick="clearOnlyOfficeLogs()"
                            class="px-4 py-2 bg-red-500 text-white rounded hover:bg-red-600 text-sm">
                        🗑️ Effacer logs
                    </button>
                </div>

                <!-- Résultat du test -->
                <div id="onlyoffice-test-result" class="hidden p-3 rounded border text-sm"></div>

                <!-- Logs -->
                <div id="onlyoffice-logs-container" class="hidden">
                    <div class="p-3 bg-gray-900 text-green-400 rounded font-mono text-xs max-h-64 overflow-y-auto">
                        <pre id="onlyoffice-logs-content">Chargement...</pre>
                    </div>
                </div>
            </div>

            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded text-xs text-blue-800">
                <strong>Usage :</strong> Prévisualisation et édition des documents Office (Word, Excel, PowerPoint) directement dans le navigateur.
                <br><span class="text-blue-600">Configuration avancée : <code>config/config.php</code> section <code>onlyoffice</code></span>
            </div>

            <div class="mt-3 p-2 bg-yellow-50 rounded border border-yellow-200 text-xs text-yellow-800">
                <strong>🔧 Dépannage :</strong> Si "Échec du téléchargement" apparaît, vérifiez que :
                <ul class="list-disc ml-4 mt-1">
                    <li>Le callback URL est accessible depuis le container Docker</li>
                    <li>Votre firewall autorise les connexions entrantes</li>
                    <li>Utilisez votre IP locale (pas localhost) pour le callback</li>
                </ul>
            </div>
        </div>

        <script>
        async function testOnlyOfficeConnectivity() {
            const btn = document.getElementById('btn-test-onlyoffice');
            const resultDiv = document.getElementById('onlyoffice-test-result');

            btn.disabled = true;
            btn.textContent = '⏳ Test en cours...';
            resultDiv.classList.remove('hidden', 'bg-green-50', 'bg-red-50', 'border-green-200', 'border-red-200');
            resultDiv.textContent = '';

            try {
                const response = await fetch('<?= url("/api/onlyoffice/test-connectivity") ?>');
                const data = await response.json();

                if (data.success) {
                    resultDiv.className = 'p-3 rounded border text-sm bg-green-50 border-green-200 text-green-800';
                    resultDiv.innerHTML = `
                        <strong>✅ Connectivité OK</strong>
                        <ul class="mt-2 list-disc ml-4">
                            <li>Serveur OnlyOffice: ${data.data.server_health ? '✓ OK' : '✗ Inaccessible'}</li>
                            <li>Callback URL: ${data.data.callback_reachable === true ? '✓ Accessible' : (data.data.callback_reachable === false ? '✗ Inaccessible' : '? Non testé')}</li>
                        </ul>
                        ${data.data.warnings?.length ? '<p class="mt-2 text-yellow-700">⚠️ ' + data.data.warnings.join('<br>⚠️ ') + '</p>' : ''}
                    `;
                } else {
                    resultDiv.className = 'p-3 rounded border text-sm bg-red-50 border-red-200 text-red-800';
                    let errorHtml = '<strong>❌ Problème de connectivité</strong><ul class="mt-2 list-disc ml-4">';
                    (data.data.errors || []).forEach(err => {
                        errorHtml += '<li>' + err + '</li>';
                    });
                    errorHtml += '</ul>';
                    resultDiv.innerHTML = errorHtml;
                }
            } catch (e) {
                resultDiv.className = 'p-3 rounded border text-sm bg-red-50 border-red-200 text-red-800';
                resultDiv.innerHTML = '<strong>❌ Erreur réseau:</strong> ' + e.message;
            }

            resultDiv.classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = '🔍 Tester la connectivité';
        }

        async function toggleOnlyOfficeLogs() {
            const container = document.getElementById('onlyoffice-logs-container');
            const content = document.getElementById('onlyoffice-logs-content');

            if (!container.classList.contains('hidden')) {
                container.classList.add('hidden');
                return;
            }

            container.classList.remove('hidden');
            content.textContent = 'Chargement...';

            try {
                const response = await fetch('<?= url("/api/onlyoffice/logs") ?>?lines=100');
                const data = await response.json();

                if (data.success && data.data.logs.length > 0) {
                    content.textContent = data.data.logs.join('\n');
                } else {
                    content.textContent = '(Aucun log disponible)';
                }
            } catch (e) {
                content.textContent = 'Erreur: ' + e.message;
            }
        }

        async function clearOnlyOfficeLogs() {
            if (!confirm('Effacer tous les logs OnlyOffice ?')) return;

            try {
                await fetch('<?= url("/api/onlyoffice/logs/clear") ?>', { method: 'POST' });
                const content = document.getElementById('onlyoffice-logs-content');
                if (content) content.textContent = '(Logs effacés)';
                alert('Logs effacés');
            } catch (e) {
                alert('Erreur: ' + e.message);
            }
        }
        </script>

        <!-- Section Recherche Sémantique (Ollama + Qdrant) — visible si infra Qdrant activée -->
        <?php if (isQdrantUiEnabled()): ?>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-gray-800">🔮 Recherche Sémantique</h2>
                <?php if (!$embeddingsEnabled): ?>
                <span class="px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded">Désactivé</span>
                <?php elseif ($ollamaStatus['ok'] && $qdrantStatus['ok']): ?>
                <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">✅ Opérationnel</span>
                <?php else: ?>
                <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded">⚠️ Partiellement configuré</span>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <!-- Ollama -->
                <div class="border rounded-lg p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium text-gray-700">Ollama (Embeddings)</span>
                        <?php if ($ollamaStatus['ok']): ?>
                        <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">✅</span>
                        <?php elseif ($embeddingsEnabled): ?>
                        <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">❌</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded">-</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500">URL : <code><?= htmlspecialchars($ollamaUrl) ?></code></p>
                    <p class="text-xs text-gray-500">Modèle : <code><?= htmlspecialchars($ollamaModel) ?></code></p>
                    <p class="text-xs mt-1 <?= $ollamaStatus['ok'] ? 'text-green-600' : 'text-red-600' ?>">
                        <?= htmlspecialchars($ollamaStatus['message']) ?>
                    </p>
                    <?php if (!empty($ollamaStatus['models'])): ?>
                    <details class="mt-2">
                        <summary class="text-xs text-blue-600 cursor-pointer">Modèles installés (<?= count($ollamaStatus['models']) ?>)</summary>
                        <ul class="text-xs text-gray-500 mt-1 ml-4 list-disc">
                            <?php foreach (array_slice($ollamaStatus['models'], 0, 10) as $model): ?>
                            <li><?= htmlspecialchars($model) ?></li>
                            <?php endforeach; ?>
                            <?php if (count($ollamaStatus['models']) > 10): ?>
                            <li>... et <?= count($ollamaStatus['models']) - 10 ?> autres</li>
                            <?php endif; ?>
                        </ul>
                    </details>
                    <?php endif; ?>
                </div>

                <!-- Qdrant -->
                <div class="border rounded-lg p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium text-gray-700">Qdrant (Vector DB)</span>
                        <?php if ($qdrantStatus['ok']): ?>
                        <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">✅</span>
                        <?php elseif ($embeddingsEnabled): ?>
                        <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">❌</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded">-</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500">URL : <code>http://<?= htmlspecialchars($qdrantHost) ?>:<?= htmlspecialchars($qdrantPort) ?></code></p>
                    <p class="text-xs text-gray-500">Collection : <code><?= htmlspecialchars($qdrantConfig['collection'] ?? 'kdocs_documents') ?></code></p>
                    <p class="text-xs mt-1 <?= $qdrantStatus['ok'] ? 'text-green-600' : 'text-red-600' ?>">
                        <?= htmlspecialchars($qdrantStatus['message']) ?>
                    </p>
                </div>
            </div>

            <div class="p-3 bg-purple-50 border border-purple-200 rounded text-xs text-purple-800">
                <strong>Usage :</strong> Recherche par sens et contexte (pas seulement mots-clés). Nécessite Ollama + Qdrant.
                <br><span class="text-purple-600">Configuration : <code>config/config.php</code> sections <code>embeddings</code> et <code>qdrant</code></span>
                <?php if (!$ollamaStatus['ok'] && $embeddingsEnabled): ?>
                <br><span class="text-red-600 mt-1 block">💡 Pour installer le modèle : <code>ollama pull <?= htmlspecialchars($ollamaModel) ?></code></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section OCR (champ éditable conservé) -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">🔍 Configuration OCR</h2>

            <div class="space-y-4">
                <div>
                    <label for="ocr_tesseract_path" class="block text-sm font-medium text-gray-700 mb-2">
                        Chemin vers Tesseract (personnalisé)
                    </label>
                    <input type="text"
                           id="ocr_tesseract_path"
                           name="ocr[tesseract_path]"
                           value="<?= htmlspecialchars($tesseractPath) ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="C:\Program Files\Tesseract-OCR\tesseract.exe">
                    <p class="mt-1 text-sm text-gray-500">
                        Laissez vide pour utiliser le chemin par défaut de <code>config/config.php</code>.
                    </p>
                </div>
            </div>
        </div>

        <!-- Section AI avec Cascade -->
        <?php
        // Récupérer le statut IA complet
        $aiProvider = new \KDocs\Services\AIProviderService();
        $aiStatus = $aiProvider->getStatus();
        
        // Comptage règles
        $db = \KDocs\Core\Database::getInstance();
        $rulesCount = 0;
        try {
            $rulesCount = (int)$db->query("SELECT COUNT(*) FROM attribution_rules WHERE active = 1")->fetchColumn();
        } catch (\Exception $e) {
            // Table peut ne pas exister
        }
        
        // Détails Ollama
        $ollamaDetails = [
            'available' => $aiStatus['ollama']['available'] ?? false,
            'url' => $aiStatus['ollama']['url'] ?? 'http://localhost:11434',
            'model' => $aiStatus['ollama']['model'] ?? 'llama3.1:8b',
            'models' => $aiStatus['ollama']['models'] ?? [],
            'has_llm' => $aiStatus['ollama']['has_llm'] ?? false,
            'has_embedding' => $aiStatus['ollama']['has_embedding'] ?? false,
        ];
        ?>
        
        <div id="ai" class="bg-white rounded-lg shadow p-6 border-2 border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-gray-800">🤖 Intelligence Artificielle - Cascade de Classification</h2>
                <?php if ($aiStatus['ai_available']): ?>
                <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">IA Disponible</span>
                <?php else: ?>
                <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded">Mode Règles uniquement</span>
                <?php endif; ?>
            </div>
            
            <!-- Cascade visuelle -->
            <div class="mb-6 p-4 bg-gradient-to-r from-blue-50 to-purple-50 rounded-lg border border-blue-200">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Ordre de priorité (fallback automatique)</h3>
                <div class="flex items-center justify-between gap-2">
                    <?php
                    $cascadeSteps = [
                        [
                            'name' => 'Claude/Anthropic',
                            'status' => $aiStatus['claude']['available'] ? 'active' : ($aiStatus['claude']['configured'] ? 'configured' : 'inactive'),
                            'icon' => '🤖',
                            'description' => 'Meilleure qualité',
                        ],
                        [
                            'name' => 'Ollama (Local)',
                            'status' => $ollamaDetails['available'] ? 'active' : 'inactive',
                            'icon' => '🖥️',
                            'description' => 'Gratuit, local',
                        ],
                        [
                            'name' => 'Règles',
                            'status' => 'always',
                            'icon' => '📋',
                            'description' => 'Toujours disponible',
                        ],
                    ];
                    
                    foreach ($cascadeSteps as $index => $step):
                        if ($index > 0):
                    ?>
                        <div class="flex-1 flex items-center justify-center">
                            <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    <?php endif; ?>
                    <div class="flex-1 text-center">
                        <div class="p-3 rounded-lg border-2 <?php
                            if ($step['status'] === 'active') echo 'border-green-500 bg-green-50';
                            elseif ($step['status'] === 'configured') echo 'border-yellow-500 bg-yellow-50';
                            elseif ($step['status'] === 'always') echo 'border-blue-500 bg-blue-50';
                            else echo 'border-gray-300 bg-gray-50';
                        ?>">
                            <div class="text-2xl mb-1"><?= $step['icon'] ?></div>
                            <div class="text-xs font-semibold text-gray-700"><?= htmlspecialchars($step['name']) ?></div>
                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($step['description']) ?></div>
                            <?php if ($step['status'] === 'active'): ?>
                            <div class="mt-1 text-xs text-green-600 font-medium">✓ Actif</div>
                            <?php elseif ($step['status'] === 'configured'): ?>
                            <div class="mt-1 text-xs text-yellow-600 font-medium">⚠ Configuré</div>
                            <?php elseif ($step['status'] === 'always'): ?>
                            <div class="mt-1 text-xs text-blue-600 font-medium">✓ Toujours</div>
                            <?php else: ?>
                            <div class="mt-1 text-xs text-gray-500">○ Inactif</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-4 text-xs text-gray-600 bg-white p-2 rounded border border-gray-200">
                    <strong>Provider actuel:</strong> 
                    <span class="font-mono text-blue-600">
                        <?php
                        $activeProvider = $aiStatus['active_provider'] ?? 'none';
                        echo match($activeProvider) {
                            'claude' => 'Claude/Anthropic',
                            'ollama' => 'Ollama (Local)',
                            default => 'Règles uniquement',
                        };
                        ?>
                    </span>
                    <?php if ($aiStatus['fallback_active'] ?? false): ?>
                    <span class="text-yellow-600 ml-2">(Mode fallback: Claude non disponible)</span>
                    <?php endif; ?>
                </div>
                
                <!-- Bouton Tester la Cascade -->
                <div class="mt-4 border-t pt-4">
                    <button type="button" 
                            id="btn-test-cascade"
                            onclick="testCascade()"
                            class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 text-sm">
                        🧪 Tester la cascade
                    </button>
                    <span id="cascade-test-result" class="ml-4 text-sm"></span>
                </div>

                <div id="cascade-test-details" class="mt-3 hidden p-3 bg-gray-50 rounded border text-xs">
                    <div><strong>Provider utilisé :</strong> <span id="test-provider" class="font-mono text-blue-600"></span></div>
                    <div class="mt-1"><strong>Modèle :</strong> <span id="test-model" class="font-mono text-gray-600"></span></div>
                    <div class="mt-1"><strong>Réponse :</strong> <span id="test-response" class="text-green-600"></span></div>
                    <div class="mt-1"><strong>Temps :</strong> <span id="test-time" class="text-gray-600"></span></div>
                </div>
            </div>
            
            <script>
            async function testCascade() {
                const btn = document.getElementById('btn-test-cascade');
                const result = document.getElementById('cascade-test-result');
                const details = document.getElementById('cascade-test-details');
                
                btn.disabled = true;
                btn.textContent = '⏳ Test en cours...';
                result.textContent = '';
                details.classList.add('hidden');
                
                try {
                    const response = await fetch('<?= url("/api/ai/test") ?>', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        result.innerHTML = '<span class="text-green-600">✅ ' + data.data.provider + ' a répondu en ' + data.data.duration_ms + 'ms</span>';
                        document.getElementById('test-provider').textContent = data.data.provider;
                        document.getElementById('test-model').textContent = data.data.model || 'N/A';
                        document.getElementById('test-response').textContent = data.data.response || 'OK';
                        document.getElementById('test-time').textContent = data.data.duration_ms + ' ms';
                        details.classList.remove('hidden');
                    } else {
                        result.innerHTML = '<span class="text-red-600">❌ ' + (data.error || 'Erreur inconnue') + '</span>';
                    }
                } catch (e) {
                    result.innerHTML = '<span class="text-red-600">❌ Erreur réseau: ' + e.message + '</span>';
                }
                
                btn.disabled = false;
                btn.textContent = '🧪 Tester la cascade';
            }
            </script>
            
            <!-- Configuration Claude -->
            <div class="mb-6 p-4 border rounded-lg <?= $aiStatus['claude']['available'] ? 'border-green-300 bg-green-50' : ($aiStatus['claude']['configured'] ? 'border-yellow-300 bg-yellow-50' : 'border-gray-200 bg-gray-50') ?>">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-700">1️⃣ Claude/Anthropic API</h3>
                    <?php if ($aiStatus['claude']['available']): ?>
                    <span class="px-2 py-1 text-xs bg-green-500 text-white rounded">✓ Disponible</span>
                    <?php elseif ($aiStatus['claude']['configured']): ?>
                    <span class="px-2 py-1 text-xs bg-yellow-500 text-white rounded">⚠ Configuré</span>
                    <?php else: ?>
                    <span class="px-2 py-1 text-xs bg-gray-400 text-white rounded">○ Non configuré</span>
                    <?php endif; ?>
                </div>
                
                <div class="space-y-3">
                    <div>
                        <label for="ai_claude_api_key" class="block text-sm font-medium text-gray-700 mb-1">
                            Clé API Claude
                        </label>
                        <input type="password" 
                               id="ai_claude_api_key" 
                               name="ai[claude_api_key]" 
                               value="<?= htmlspecialchars($claudeApiKey ?? '') ?>"
                               class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="sk-ant-api03-...">
                        <p class="mt-1 text-xs text-gray-500">
                            <a href="https://console.anthropic.com/" target="_blank" class="text-blue-600 hover:underline">Obtenir une clé API</a>
                        </p>
                    </div>
                    
                    <div class="text-xs text-gray-600">
                        <strong>Modèle:</strong> <?= htmlspecialchars($aiStatus['claude']['model'] ?? 'claude-sonnet-4-20250514') ?>
                        <?php if (isset($aiStatus['claude']['error'])): ?>
                        <br><span class="text-red-600">Erreur: <?= htmlspecialchars($aiStatus['claude']['error']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Configuration Ollama -->
            <div class="mb-6 p-4 border rounded-lg <?= $ollamaDetails['available'] ? 'border-green-300 bg-green-50' : 'border-gray-200 bg-gray-50' ?>">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-700">2️⃣ Ollama (Local)</h3>
                    <?php if ($ollamaDetails['available']): ?>
                    <span class="px-2 py-1 text-xs bg-green-500 text-white rounded">✓ Connecté</span>
                    <?php else: ?>
                    <span class="px-2 py-1 text-xs bg-gray-400 text-white rounded">○ Déconnecté</span>
                    <?php endif; ?>
                </div>
                
                <div class="space-y-2 text-xs">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <strong class="text-gray-600">URL:</strong>
                            <code class="text-gray-700"><?= htmlspecialchars($ollamaDetails['url']) ?></code>
                        </div>
                        <div>
                            <strong class="text-gray-600">Modèle configuré:</strong>
                            <code class="text-gray-700"><?= htmlspecialchars($ollamaDetails['model']) ?></code>
                        </div>
                    </div>
                    
                    <?php if ($ollamaDetails['available']): ?>
                        <div class="mt-2 p-2 bg-white rounded border border-gray-200">
                            <strong class="text-gray-600">Modèles installés:</strong> <?= count($ollamaDetails['models']) ?>
                            <?php if (!empty($ollamaDetails['models'])): ?>
                            <details class="mt-1">
                                <summary class="text-blue-600 cursor-pointer hover:underline">Voir la liste</summary>
                                <ul class="mt-1 ml-4 list-disc text-gray-600">
                                    <?php foreach (array_slice($ollamaDetails['models'], 0, 10) as $model): ?>
                                    <li class="font-mono text-xs"><?= htmlspecialchars($model) ?></li>
                                    <?php endforeach; ?>
                                    <?php if (count($ollamaDetails['models']) > 10): ?>
                                    <li class="text-gray-400">... et <?= count($ollamaDetails['models']) - 10 ?> autres</li>
                                    <?php endif; ?>
                                </ul>
                            </details>
                            <?php endif; ?>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-2 mt-2">
                            <div class="p-2 bg-white rounded border <?= $ollamaDetails['has_llm'] ? 'border-green-300' : 'border-yellow-300' ?>">
                                <strong class="text-gray-600">Modèle LLM:</strong>
                                <?php if ($ollamaDetails['has_llm']): ?>
                                <span class="text-green-600">✓ Disponible</span>
                                <?php else: ?>
                                <span class="text-yellow-600">⚠ Non trouvé</span>
                                <?php endif; ?>
                                <p class="text-xs text-gray-500 mt-1">Pour classification/chat</p>
                            </div>
                            <div class="p-2 bg-white rounded border <?= $ollamaDetails['has_embedding'] ? 'border-green-300' : 'border-yellow-300' ?>">
                                <strong class="text-gray-600">Modèle Embedding:</strong>
                                <?php if ($ollamaDetails['has_embedding']): ?>
                                <span class="text-green-600">✓ Disponible</span>
                                <?php else: ?>
                                <span class="text-yellow-600">⚠ Non trouvé</span>
                                <?php endif; ?>
                                <p class="text-xs text-gray-500 mt-1">Pour recherche sémantique</p>
                            </div>
                        </div>
                        
                        <?php
                        // Vérifier si le modèle configuré est installé
                        $modelInstalled = in_array($ollamaDetails['model'], $ollamaDetails['models']) 
                            || in_array($ollamaDetails['model'] . ':latest', $ollamaDetails['models']);
                        if (!$modelInstalled && $ollamaDetails['available']):
                        ?>
                        <div class="mt-2 p-2 bg-yellow-50 border border-yellow-300 rounded text-xs">
                            <strong class="text-yellow-800">⚠ Modèle non installé:</strong>
                            <code class="text-yellow-700"><?= htmlspecialchars($ollamaDetails['model']) ?></code>
                            <p class="text-yellow-700 mt-1">
                                Installez-le avec: <code class="bg-yellow-100 px-1 rounded">ollama pull <?= htmlspecialchars($ollamaDetails['model']) ?></code>
                            </p>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="mt-2 p-2 bg-gray-100 rounded text-xs text-gray-600">
                            <strong>Ollama n'est pas accessible.</strong> Assurez-vous qu'Ollama est démarré et accessible à l'URL configurée.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Règles -->
            <div class="mb-4 p-4 border border-blue-300 bg-blue-50 rounded-lg">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold text-gray-700">3️⃣ Règles d'Attribution</h3>
                    <span class="px-2 py-1 text-xs bg-blue-500 text-white rounded">✓ Toujours actif</span>
                </div>
                <div class="text-xs text-gray-600">
                    <strong><?= $rulesCount ?> règles actives</strong> - Fallback garanti même sans IA
                    <a href="<?= url('/admin/attribution-rules') ?>" class="text-blue-600 hover:underline ml-2">Gérer les règles →</a>
                </div>
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

        <!-- Section Indexation -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold mb-4">⚙️ Paramètres d'indexation</h2>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Queues simultanées max
                    </label>
                    <input type="number" name="indexing_max_concurrent_queues" 
                           value="<?= htmlspecialchars($indexingMaxQueues) ?>"
                           min="1" max="10"
                           class="w-full px-3 py-2 border rounded">
                    <p class="text-xs text-gray-500 mt-1">1-10. Plus = plus rapide mais plus de charge</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Mémoire par worker (MB)
                    </label>
                    <input type="number" name="indexing_memory_limit" 
                           value="<?= htmlspecialchars($indexingMemoryLimit) ?>"
                           min="64" max="512"
                           class="w-full px-3 py-2 border rounded">
                    <p class="text-xs text-gray-500 mt-1">64-512 MB</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Pause entre fichiers (ms)
                    </label>
                    <input type="number" name="indexing_delay_between_files" 
                           value="<?= htmlspecialchars($indexingDelayFiles) ?>"
                           min="0" max="1000"
                           class="w-full px-3 py-2 border rounded">
                    <p class="text-xs text-gray-500 mt-1">0 = pas de pause, 50-100 recommandé</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Fichiers par batch
                    </label>
                    <input type="number" name="indexing_batch_size" 
                           value="<?= htmlspecialchars($indexingBatchSize) ?>"
                           min="5" max="100"
                           class="w-full px-3 py-2 border rounded">
                    <p class="text-xs text-gray-500 mt-1">Pause longue après ce nombre de fichiers</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Pause après batch (ms)
                    </label>
                    <input type="number" name="indexing_batch_pause" 
                           value="<?= htmlspecialchars($indexingBatchPause) ?>"
                           min="0" max="5000"
                           class="w-full px-3 py-2 border rounded">
                </div>
                
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" name="indexing_turbo_mode" value="1"
                               <?= $indexingTurboMode === '1' ? 'checked' : '' ?>
                               class="mr-2">
                        <span class="text-sm font-medium text-gray-700">Mode Turbo</span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1">Ignore toutes les pauses (charge max)</p>
                </div>
            </div>
            
            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded">
                <p class="text-xs text-blue-800">
                    <strong>💡 Astuce :</strong> Ces paramètres contrôlent l'indexation en arrière-plan. 
                    Réduisez les pauses pour une indexation plus rapide, mais augmentez-les si le serveur est surchargé.
                </p>
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
