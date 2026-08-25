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
        <h1 class="text-2xl font-bold" style="color:var(--ink)">⚙️ Paramètres système</h1>
    </div>

    <?php if ($successMsg): ?>
    <div class="border px-4 py-3 rounded-lg" style="background:color-mix(in srgb,var(--green) 12%,transparent);border-color:var(--green);color:var(--green)">
        ✅ <?= htmlspecialchars($successMsg) ?>
    </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
    <div class="border px-4 py-3 rounded-lg" style="background:color-mix(in srgb,var(--red) 12%,transparent);border-color:var(--red);color:var(--red)">
        ❌ <?= htmlspecialchars($errorMsg) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/admin/settings/save') ?>" class="space-y-6">
        
        <!-- Section Stockage -->
        <div class="rounded-lg shadow p-6" style="background:var(--surface)">
            <h2 class="text-xl font-semibold mb-4" style="color:var(--ink)">📁 Stockage</h2>
            
            <div class="space-y-4">
                <div>
                    <label for="storage_type" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                        Type de stockage
                    </label>
                    <select id="storage_type" 
                            name="storage[type]" 
                            class="form-select"
                            onchange="toggleStorageType()">
                        <option value="local" <?= $storageType === 'local' ? 'selected' : '' ?>>Local (Filesystem)</option>
                        <option value="kdrive" <?= $storageType === 'kdrive' ? 'selected' : '' ?>>KDrive (Infomaniak)</option>
                    </select>
                    <p class="mt-1 text-sm" style="color:var(--dim)">
                        Choisissez le type de stockage pour vos documents.
                    </p>
                </div>
                
                <!-- Configuration Local -->
                <div id="storage-local-config">
                    <div>
                        <label for="storage_base_path" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                            Racine des documents (base_path)
                        </label>
                        <input type="text" 
                               id="storage_base_path" 
                               name="storage[base_path]" 
                               value="<?= htmlspecialchars($basePath) ?>"
                               class="form-input"
                               placeholder="C:\wamp64\www\kdocs\storage\documents">
                        <p class="mt-1 text-sm" style="color:var(--dim)">
                            Chemin racine où sont stockés les documents. Laissez vide pour utiliser la valeur par défaut.
                        </p>
                        <?php if ($basePath && is_dir($basePath)): ?>                        <p class="mt-1 text-sm" style="color:var(--green)">✅ Le dossier existe</p>
                        <?php elseif ($basePath): ?>
                        <p class="mt-1 text-sm" style="color:var(--red)">❌ Le dossier n'existe pas</p>
                        <?php endif; ?>
                    </div>

                    <?php
                    // DF-05 / D-GED-08 : le dossier surveillé doit être réglable
                    // depuis l'interface (il était en dur dans config.php, aucun
                    // champ ne permettait de le changer — écran orphelin dénoncé
                    // le 2026-08-11). Lu par ConsumeFolderService via
                    // Config::get('storage.consume') — les settings DB ont priorité.
                    $consumePath = Config::get('storage.consume', $defaultConfig['storage']['consume'] ?? '');
                    try {
                        $consumeLastRun = \KDocs\Core\Database::getInstance()
                            ->query("SELECT last_run_at FROM scheduled_tasks WHERE name = 'scan_consume_folder'")
                            ->fetchColumn();
                    } catch (\Exception $e) {
                        $consumeLastRun = false;
                    }
                    ?>
                    <div>
                        <label for="storage_consume" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                            Dossier surveillé (consommation automatique)
                        </label>
                        <input type="text"
                               id="storage_consume"
                               name="storage[consume]"
                               value="<?= htmlspecialchars((string) $consumePath) ?>"
                               class="form-input"
                               placeholder="C:\wamp64\www\kdocs\storage\consume">
                        <p class="mt-1 text-sm" style="color:var(--dim)">
                            Tout fichier déposé dans ce dossier est ingéré, découpé et proposé au classement.
                            Le dossier reste invisible de la bibliothèque. Laissez vide pour la valeur par défaut.
                        </p>
                        <?php if ($consumePath && is_dir($consumePath)): ?>
                        <p class="mt-1 text-sm" style="color:var(--green)">✅ Le dossier existe</p>
                        <?php elseif ($consumePath): ?>
                        <p class="mt-1 text-sm" style="color:var(--red)">❌ Le dossier n'existe pas</p>
                        <?php endif; ?>
                        <p class="mt-1 text-sm" style="color:var(--dim)">
                            Dernier scan planifié : <?= $consumeLastRun ? htmlspecialchars((string) $consumeLastRun) : 'jamais' ?>
                            — le scan se déclenche aussi à l'ouverture de la page « Fichiers à valider ».
                        </p>
                    </div>
                </div>
                
                <!-- Configuration KDrive -->
                <div id="storage-kdrive-config" style="display: <?= $storageType === 'kdrive' ? 'block' : 'none' ?>;">
                    <div class="space-y-4">
                        <div>
                            <label for="kdrive_drive_id" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                                Drive ID
                            </label>
                            <input type="text" 
                                   id="kdrive_drive_id" 
                                   name="kdrive[drive_id]" 
                                   value="<?= htmlspecialchars($kdriveDriveId) ?>"
                                   class="form-input"
                                   placeholder="123456">
                            <p class="mt-1 text-sm" style="color:var(--dim)">
                                ID du Drive KDrive (extrait de l'URL : /drive/123456/). Trouvez-le dans l'URL de votre kDrive.
                            </p>
                        </div>
                        
                        <div>
                            <label for="kdrive_username" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                                Email Infomaniak
                            </label>
                            <input type="email" 
                                   id="kdrive_username" 
                                   name="kdrive[username]" 
                                   value="<?= htmlspecialchars($kdriveUsername) ?>"
                                   class="form-input"
                                   placeholder="votre@email.infomaniak.com">
                            <p class="mt-1 text-sm" style="color:var(--dim)">
                                Email de votre compte Infomaniak.
                            </p>
                        </div>
                        
                        <div>
                            <label for="kdrive_password" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                                Mot de passe d'application
                            </label>
                            <input type="password" 
                                   id="kdrive_password" 
                                   name="kdrive[password]" 
                                   value="<?= htmlspecialchars($kdrivePassword) ?>"
                                   class="form-input"
                                   placeholder="Mot de passe d'application">
                            <p class="mt-1 text-sm" style="color:var(--dim)">
                                Si vous avez activé l'authentification à deux facteurs, créez un mot de passe d'application dans les paramètres Infomaniak.
                            </p>
                        </div>
                        
                        <div>
                            <label for="kdrive_base_path" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                                Chemin de base dans KDrive (optionnel)
                            </label>
                            <input type="text" 
                                   id="kdrive_base_path" 
                                   name="kdrive[base_path]" 
                                   value="<?= htmlspecialchars($kdriveBasePath) ?>"
                                   class="form-input"
                                   placeholder="Documents/K-Docs">
                            <p class="mt-1 text-sm" style="color:var(--dim)">
                                Dossier de base dans KDrive (laissez vide pour utiliser la racine du Drive).
                            </p>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label for="storage_allowed_extensions" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                        Extensions autorisées
                    </label>
                    <input type="text" 
                           id="storage_allowed_extensions" 
                           name="storage[allowed_extensions]" 
                           value="<?= htmlspecialchars($allowedExtensions) ?>"
                           class="form-input"
                           placeholder="pdf,png,jpg,jpeg,tiff,doc,docx">
                    <p class="mt-1 text-sm" style="color:var(--dim)">
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

        <div class="rounded-lg shadow p-6" style="background:var(--surface)">
            <h2 class="text-xl font-semibold mb-4" style="color:var(--ink)">🔧 Outils système</h2>
            <p class="text-sm mb-4" style="color:var(--dim)">Statut des outils externes utilisés par K-Docs. Les chemins sont configurés dans <code>config/config.php</code>.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Tesseract OCR -->
                <div class="border rounded-lg p-4" style="border-color:var(--border)">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium" style="color:var(--ink-soft)">Tesseract OCR</span>
                        <?php if ($tesseractPath && file_exists($tesseractPath)): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">✅ Disponible</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--red">❌ Non trouvé</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs truncate" style="color:var(--dim)" title="<?= htmlspecialchars($tesseractPath) ?>">
                        <?= htmlspecialchars($tesseractPath ?: 'Non configuré') ?>
                    </p>
                    <p class="text-xs mt-1" style="color:var(--dim)">Extraction de texte des images et PDFs scannés</p>
                </div>

                <!-- Ghostscript -->
                <div class="border rounded-lg p-4" style="border-color:var(--border)">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium" style="color:var(--ink-soft)">Ghostscript</span>
                        <?php if ($ghostscriptPath && file_exists($ghostscriptPath)): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">✅ Disponible</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--red">❌ Non trouvé</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs truncate" style="color:var(--dim)" title="<?= htmlspecialchars($ghostscriptPath) ?>">
                        <?= htmlspecialchars($ghostscriptPath ?: 'Non configuré') ?>
                    </p>
                    <p class="text-xs mt-1" style="color:var(--dim)">Conversion et rendu PDF, génération de miniatures</p>
                </div>

                <!-- pdftotext -->
                <div class="border rounded-lg p-4" style="border-color:var(--border)">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium" style="color:var(--ink-soft)">pdftotext (Poppler)</span>
                        <?php if ($pdftotextPath && file_exists($pdftotextPath)): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">✅ Disponible</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--red">❌ Non trouvé</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs truncate" style="color:var(--dim)" title="<?= htmlspecialchars($pdftotextPath) ?>">
                        <?= htmlspecialchars($pdftotextPath ?: 'Non configuré') ?>
                    </p>
                    <p class="text-xs mt-1" style="color:var(--dim)">Extraction de texte natif des PDFs</p>
                </div>

                <!-- pdftoppm -->
                <div class="border rounded-lg p-4" style="border-color:var(--border)">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium" style="color:var(--ink-soft)">pdftoppm (Poppler)</span>
                        <?php if ($pdftoppmPath && file_exists($pdftoppmPath)): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">✅ Disponible</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--red">❌ Non trouvé</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs truncate" style="color:var(--dim)" title="<?= htmlspecialchars($pdftoppmPath) ?>">
                        <?= htmlspecialchars($pdftoppmPath ?: 'Non configuré') ?>
                    </p>
                    <p class="text-xs mt-1" style="color:var(--dim)">Conversion PDF vers images (miniatures)</p>
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
                <div class="border rounded-lg p-4" style="border-color:var(--border)">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium" style="color:var(--ink-soft)">LibreOffice</span>
                        <?php if ($libreofficePath && file_exists($libreofficePath)): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">✅ <?= $libreofficeVersion ? 'v' . htmlspecialchars($libreofficeVersion) : 'Disponible' ?></span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--amber">⚠️ Non trouvé</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs truncate" style="color:var(--dim)" title="<?= htmlspecialchars($libreofficePath) ?>">
                        <?= htmlspecialchars($libreofficePath ?: 'Non configuré') ?>
                    </p>
                    <p class="text-xs mt-1" style="color:var(--dim)">Conversion DOCX, XLSX, PPTX → PDF/miniatures</p>
                </div>

            </div>
        </div>

        <!-- Section OnlyOffice -->
        <?php
        $onlyofficeSslVerify = $onlyofficeConfig['ssl_verify'] ?? false;
        $onlyofficeDebugLog = $onlyofficeConfig['debug_log'] ?? false;
        $onlyofficeCallbackUrl = $onlyofficeConfig['callback_url'] ?? '';
        ?>
        <div class="rounded-lg shadow p-6" style="background:var(--surface)">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold" style="color:var(--ink)">📄 OnlyOffice Document Server</h2>
                <?php if (!$onlyofficeEnabled): ?>
                <span class="px-2 py-1 text-xs ds-chip ds-chip--neutral">Désactivé</span>
                <?php elseif ($onlyofficeStatus['ok']): ?>
                <span class="px-2 py-1 text-xs ds-chip ds-chip--green">✅ <?= htmlspecialchars($onlyofficeStatus['message']) ?></span>
                <?php else: ?>
                <span class="px-2 py-1 text-xs ds-chip ds-chip--red">❌ <?= htmlspecialchars($onlyofficeStatus['message']) ?></span>
                <?php endif; ?>
            </div>

            <div class="space-y-4">
                <!-- Statut actuel -->
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div class="flex items-center justify-between">
                        <span style="color:var(--ink-soft)">URL du serveur</span>
                        <code class="text-xs px-2 py-1 rounded" style="background:var(--rail)"><?= htmlspecialchars($onlyofficeUrl) ?></code>
                    </div>
                    <div class="flex items-center justify-between">
                        <span style="color:var(--ink-soft)">JWT Secret</span>
                        <span class="text-xs" style="<?= empty($onlyofficeConfig['jwt_secret']) ? 'color:var(--amber)' : 'color:var(--green)' ?>">
                            <?= empty($onlyofficeConfig['jwt_secret']) ? '⚠️ Non configuré' : '✅ Configuré' ?>
                        </span>
                    </div>
                </div>

                <!-- Configuration Callback URL -->
                <div class="border-t pt-4">
                    <label class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                        Callback URL (pour Docker)
                    </label>
                    <input type="text"
                           id="onlyoffice_callback_url"
                           name="onlyoffice[callback_url]"
                           value="<?= htmlspecialchars($onlyofficeCallbackUrl) ?>"
                           class="form-input text-sm"
                           placeholder="http://192.168.1.x/kdocs ou http://host.docker.internal/kdocs">
                    <p class="mt-1 text-xs" style="color:var(--dim)">
                        URL que le container Docker OnlyOffice utilise pour atteindre K-Docs.
                        <strong>Ne pas utiliser localhost</strong> - utilisez votre IP locale ou <code>host.docker.internal</code>.
                    </p>
                </div>

                <!-- Options -->
                <div class="grid grid-cols-2 gap-4 border-t pt-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="onlyoffice[ssl_verify]" value="1"
                               <?= $onlyofficeSslVerify ? 'checked' : '' ?>
                               class="mr-2 rounded" style="accent-color:var(--accent)">
                        <span class="text-sm" style="color:var(--ink-soft)">Vérification SSL</span>
                    </label>
                    <label class="flex items-center">
                        <input type="checkbox" name="onlyoffice[debug_log]" value="1"
                               <?= $onlyofficeDebugLog ? 'checked' : '' ?>
                               class="mr-2 rounded" style="accent-color:var(--accent)">
                        <span class="text-sm" style="color:var(--ink-soft)">Logs détaillés</span>
                    </label>
                </div>
                <p class="text-xs -mt-2" style="color:var(--dim)">
                    ⚠️ Désactivez la vérification SSL en développement si vous avez des erreurs de certificat.
                    Logs stockés dans <code>storage/logs/onlyoffice.log</code>
                </p>

                <!-- Boutons de diagnostic -->
                <div class="flex gap-3 border-t pt-4">
                    <button type="button"
                            id="btn-test-onlyoffice"
                            onclick="testOnlyOfficeConnectivity()"
                            class="btn-secondary border px-4 py-2 rounded text-sm">
                        🔍 Tester la connectivité
                    </button>
                    <button type="button"
                            id="btn-view-onlyoffice-logs"
                            onclick="toggleOnlyOfficeLogs()"
                            class="btn-secondary border px-4 py-2 rounded text-sm">
                        📋 Voir les logs
                    </button>
                    <button type="button"
                            id="btn-clear-onlyoffice-logs"
                            onclick="clearOnlyOfficeLogs()"
                            class="btn-danger px-4 py-2 rounded text-sm">
                        🗑️ Effacer logs
                    </button>
                </div>

                <!-- Résultat du test -->
                <div id="onlyoffice-test-result" class="hidden p-3 rounded border text-sm"></div>

                <!-- Logs -->
                <div id="onlyoffice-logs-container" class="hidden">
                    <div class="p-3 rounded font-mono text-xs max-h-64 overflow-y-auto" style="background:var(--tip);color:var(--green)">
                        <pre id="onlyoffice-logs-content">Chargement...</pre>
                    </div>
                </div>
            </div>

            <div class="mt-4 p-3 border rounded text-xs" style="background:var(--accent-soft);border-color:var(--accent);color:var(--ink-soft)">
                <strong>Usage :</strong> Prévisualisation et édition des documents Office (Word, Excel, PowerPoint) directement dans le navigateur.
                <br><span style="color:var(--accent)">Configuration avancée : <code>config/config.php</code> section <code>onlyoffice</code></span>
            </div>

            <div class="mt-3 p-2 rounded border text-xs" style="background:color-mix(in srgb,var(--amber) 12%,transparent);border-color:var(--amber);color:var(--amber)">
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
            resultDiv.classList.remove('hidden'); resultDiv.style.cssText = '';
            resultDiv.textContent = '';

            try {
                const response = await fetch('<?= url("/api/onlyoffice/test-connectivity") ?>');
                const data = await response.json();

                if (data.success) {
                    resultDiv.className = 'p-3 rounded border text-sm'; resultDiv.style.cssText = 'background:color-mix(in srgb,var(--green) 12%,transparent);border-color:var(--green);color:var(--green)';
                    resultDiv.innerHTML = `
                        <strong>✅ Connectivité OK</strong>
                        <ul class="mt-2 list-disc ml-4">
                            <li>Serveur OnlyOffice: ${data.data.server_health ? '✓ OK' : '✗ Inaccessible'}</li>
                            <li>Callback URL: ${data.data.callback_reachable === true ? '✓ Accessible' : (data.data.callback_reachable === false ? '✗ Inaccessible' : '? Non testé')}</li>
                        </ul>
                        ${data.data.warnings?.length ? '<p class="mt-2" style="color:var(--amber)">⚠️ ' + data.data.warnings.join('<br>⚠️ ') + '</p>' : ''}
                    `;
                } else {
                    resultDiv.className = 'p-3 rounded border text-sm'; resultDiv.style.cssText = 'background:color-mix(in srgb,var(--red) 12%,transparent);border-color:var(--red);color:var(--red)';
                    let errorHtml = '<strong>❌ Problème de connectivité</strong><ul class="mt-2 list-disc ml-4">';
                    (data.data.errors || []).forEach(err => {
                        errorHtml += '<li>' + err + '</li>';
                    });
                    errorHtml += '</ul>';
                    resultDiv.innerHTML = errorHtml;
                }
            } catch (e) {
                resultDiv.className = 'p-3 rounded border text-sm'; resultDiv.style.cssText = 'background:color-mix(in srgb,var(--red) 12%,transparent);border-color:var(--red);color:var(--red)';
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
        <div class="rounded-lg shadow p-6" style="background:var(--surface)">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold" style="color:var(--ink)">🔮 Recherche Sémantique</h2>
                <?php if (!$embeddingsEnabled): ?>
                <span class="px-2 py-1 text-xs ds-chip ds-chip--neutral">Désactivé</span>
                <?php elseif ($ollamaStatus['ok'] && $qdrantStatus['ok']): ?>
                <span class="px-2 py-1 text-xs ds-chip ds-chip--green">✅ Opérationnel</span>
                <?php else: ?>
                <span class="px-2 py-1 text-xs ds-chip ds-chip--amber">⚠️ Partiellement configuré</span>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <!-- Ollama -->
                <div class="border rounded-lg p-4" style="border-color:var(--border)">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium" style="color:var(--ink-soft)">Ollama (Embeddings)</span>
                        <?php if ($ollamaStatus['ok']): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">✅</span>
                        <?php elseif ($embeddingsEnabled): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--red">❌</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--neutral">-</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs" style="color:var(--dim)">URL : <code><?= htmlspecialchars($ollamaUrl) ?></code></p>
                    <p class="text-xs" style="color:var(--dim)">Modèle : <code><?= htmlspecialchars($ollamaModel) ?></code></p>
                    <p class="text-xs mt-1" style="<?= $ollamaStatus['ok'] ? 'color:var(--green)' : 'color:var(--red)' ?>">
                        <?= htmlspecialchars($ollamaStatus['message']) ?>
                    </p>
                    <?php if (!empty($ollamaStatus['models'])): ?>
                    <details class="mt-2">
                        <summary class="text-xs cursor-pointer" style="color:var(--accent)">Modèles installés (<?= count($ollamaStatus['models']) ?>)</summary>
                        <ul class="text-xs mt-1 ml-4 list-disc" style="color:var(--dim)">
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
                <div class="border rounded-lg p-4" style="border-color:var(--border)">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-medium" style="color:var(--ink-soft)">Qdrant (Vector DB)</span>
                        <?php if ($qdrantStatus['ok']): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">✅</span>
                        <?php elseif ($embeddingsEnabled): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--red">❌</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--neutral">-</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs" style="color:var(--dim)">URL : <code>http://<?= htmlspecialchars($qdrantHost) ?>:<?= htmlspecialchars($qdrantPort) ?></code></p>
                    <p class="text-xs" style="color:var(--dim)">Collection : <code><?= htmlspecialchars($qdrantConfig['collection'] ?? 'kdocs_documents') ?></code></p>
                    <p class="text-xs mt-1" style="<?= $qdrantStatus['ok'] ? 'color:var(--green)' : 'color:var(--red)' ?>">
                        <?= htmlspecialchars($qdrantStatus['message']) ?>
                    </p>
                </div>
            </div>

            <div class="p-3 border rounded text-xs" style="background:var(--accent-soft);border-color:var(--accent);color:var(--ink-soft)">
                <strong>Usage :</strong> Recherche par sens et contexte (pas seulement mots-clés). Nécessite Ollama + Qdrant.
                <br><span style="color:var(--accent)">Configuration :<code>config/config.php</code> sections <code>embeddings</code> et <code>qdrant</code></span>
                <?php if (!$ollamaStatus['ok'] && $embeddingsEnabled): ?>
                <br><span class="mt-1 block" style="color:var(--red)">💡 Pour installer le modèle : <code>ollama pull <?= htmlspecialchars($ollamaModel) ?></code></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section OCR (champ éditable conservé) -->
        <div class="rounded-lg shadow p-6" style="background:var(--surface)">
            <h2 class="text-xl font-semibold mb-4" style="color:var(--ink)">🔍 Configuration OCR</h2>

            <div class="space-y-4">
                <div>
                    <label for="ocr_tesseract_path" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                        Chemin vers Tesseract (personnalisé)
                    </label>
                    <input type="text"
                           id="ocr_tesseract_path"
                           name="ocr[tesseract_path]"
                           value="<?= htmlspecialchars($tesseractPath) ?>"
                           class="form-input"
                           placeholder="C:\Program Files\Tesseract-OCR\tesseract.exe">
                    <p class="mt-1 text-sm" style="color:var(--dim)">
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
        
        <div id="ai" class="rounded-lg shadow p-6 border-2" style="background:var(--surface);border-color:var(--border)">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold" style="color:var(--ink)">🤖 Intelligence Artificielle - Cascade de Classification</h2>
                <?php if ($aiStatus['ai_available']): ?>
                <span class="px-2 py-1 text-xs ds-chip ds-chip--green">IA Disponible</span>
                <?php else: ?>
                <span class="px-2 py-1 text-xs ds-chip ds-chip--amber">Mode Règles uniquement</span>
                <?php endif; ?>
            </div>
            
            <!-- Cascade visuelle -->
            <div class="mb-6 p-4 rounded-lg border" style="background:var(--rail);border-color:var(--border)">
                <h3 class="text-sm font-semibold mb-3" style="color:var(--ink-soft)">Ordre de priorité (fallback automatique)</h3>
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
                            <svg class="w-6 h-6" style="color:var(--dim)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    <?php endif; ?>
                    <div class="flex-1 text-center">
                        <div class="p-3 rounded-lg border-2" style="<?php
                            if ($step['status'] === 'active') echo 'border-color:var(--green);background:color-mix(in srgb,var(--green) 12%,transparent)';
                            elseif ($step['status'] === 'configured') echo 'border-color:var(--amber);background:color-mix(in srgb,var(--amber) 12%,transparent)';
                            elseif ($step['status'] === 'always') echo 'border-color:var(--accent);background:var(--accent-soft)';
                            else echo 'border-color:var(--border);background:var(--app-bg)';
                        ?>">
                            <div class="text-2xl mb-1"><?= $step['icon'] ?></div>
                            <div class="text-xs font-semibold" style="color:var(--ink-soft)"><?= htmlspecialchars($step['name']) ?></div>
                            <div class="text-xs mt-1" style="color:var(--dim)"><?= htmlspecialchars($step['description']) ?></div>
                            <?php if ($step['status'] === 'active'): ?>
                            <div class="mt-1 text-xs font-medium" style="color:var(--green)">✓ Actif</div>
                            <?php elseif ($step['status'] === 'configured'): ?>
                            <div class="mt-1 text-xs font-medium" style="color:var(--amber)">⚠ Configuré</div>
                            <?php elseif ($step['status'] === 'always'): ?>
                            <div class="mt-1 text-xs font-medium" style="color:var(--accent)">✓ Toujours</div>
                            <?php else: ?>
                            <div class="mt-1 text-xs" style="color:var(--dim)">○ Inactif</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-4 text-xs p-2 rounded border" style="color:var(--ink-soft);background:var(--surface);border-color:var(--border)">
                    <strong>Provider actuel:</strong> 
                    <span class="font-mono" style="color:var(--accent)">
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
                    <span class="ml-2" style="color:var(--amber)">(Mode fallback: Claude non disponible)</span>
                    <?php endif; ?>
                </div>
                
                <!-- Bouton Tester la Cascade -->
                <div class="mt-4 border-t pt-4">
                    <button type="button" 
                            id="btn-test-cascade"
                            onclick="testCascade()"
                            class="btn-secondary border px-4 py-2 rounded text-sm">
                        🧪 Tester la cascade
                    </button>
                    <span id="cascade-test-result" class="ml-4 text-sm"></span>
                </div>

                <div id="cascade-test-details" class="mt-3 hidden p-3 rounded border text-xs" style="background:var(--app-bg);border-color:var(--border)">
                    <div><strong>Provider utilisé :</strong> <span id="test-provider" class="font-mono" style="color:var(--accent)"></span></div>
                    <div class="mt-1"><strong>Modèle :</strong> <span id="test-model" class="font-mono" style="color:var(--ink-soft)"></span></div>
                    <div class="mt-1"><strong>Réponse :</strong> <span id="test-response" style="color:var(--green)"></span></div>
                    <div class="mt-1"><strong>Temps :</strong> <span id="test-time" style="color:var(--ink-soft)"></span></div>
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
                        result.innerHTML = '<span style="color:var(--green)">✅ ' + data.data.provider + ' a répondu en ' + data.data.duration_ms + 'ms</span>';
                        document.getElementById('test-provider').textContent = data.data.provider;
                        document.getElementById('test-model').textContent = data.data.model || 'N/A';
                        document.getElementById('test-response').textContent = data.data.response || 'OK';
                        document.getElementById('test-time').textContent = data.data.duration_ms + ' ms';
                        details.classList.remove('hidden');
                    } else {
                        result.innerHTML = '<span style="color:var(--red)">❌ ' + (data.error || 'Erreur inconnue') + '</span>';
                    }
                } catch (e) {
                    result.innerHTML = '<span style="color:var(--red)">❌ Erreur réseau: ' + e.message + '</span>';
                }
                
                btn.disabled = false;
                btn.textContent = '🧪 Tester la cascade';
            }
            </script>
            
            <!-- Configuration Claude -->
            <div class="mb-6 p-4 border rounded-lg" style="<?= $aiStatus['claude']['available'] ? 'border-color:var(--green);background:color-mix(in srgb,var(--green) 10%,transparent)' : ($aiStatus['claude']['configured'] ? 'border-color:var(--amber);background:color-mix(in srgb,var(--amber) 10%,transparent)' : 'border-color:var(--border);background:var(--app-bg)') ?>">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold" style="color:var(--ink-soft)">1️⃣ Claude/Anthropic API</h3>
                    <?php if ($aiStatus['claude']['available']): ?>
                    <span class="px-2 py-1 text-xs rounded" style="background:var(--green);color:#fff">✓ Disponible</span>
                    <?php elseif ($aiStatus['claude']['configured']): ?>
                    <span class="px-2 py-1 text-xs rounded" style="background:var(--amber);color:#fff">⚠ Configuré</span>
                    <?php else: ?>
                    <span class="px-2 py-1 text-xs rounded" style="background:var(--dim);color:#fff">○ Non configuré</span>
                    <?php endif; ?>
                </div>
                
                <div class="space-y-3">
                    <div>
                        <label for="ai_claude_api_key" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">
                            Clé API Claude
                        </label>
                        <input type="password" 
                               id="ai_claude_api_key" 
                               name="ai[claude_api_key]" 
                               value="<?= htmlspecialchars($claudeApiKey ?? '') ?>"
                               class="form-input text-sm"
                               placeholder="sk-ant-api03-...">
                        <p class="mt-1 text-xs" style="color:var(--dim)">
                            <a href="https://console.anthropic.com/" target="_blank" class="hover:underline" style="color:var(--accent)">Obtenir une clé API</a>
                        </p>
                    </div>
                    
                    <div class="text-xs" style="color:var(--ink-soft)">
                        <strong>Modèle:</strong> <?= htmlspecialchars($aiStatus['claude']['model'] ?? 'claude-sonnet-4-20250514') ?>
                        <?php if (isset($aiStatus['claude']['error'])): ?>
                        <br><span style="color:var(--red)">Erreur: <?= htmlspecialchars($aiStatus['claude']['error']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Configuration Ollama -->
            <div class="mb-6 p-4 border rounded-lg" style="<?= $ollamaDetails['available'] ? 'border-color:var(--green);background:color-mix(in srgb,var(--green) 10%,transparent)' : 'border-color:var(--border);background:var(--app-bg)' ?>">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold" style="color:var(--ink-soft)">2️⃣ Ollama (Local)</h3>
                    <?php if ($ollamaDetails['available']): ?>
                    <span class="px-2 py-1 text-xs rounded" style="background:var(--green);color:#fff">✓ Connecté</span>
                    <?php else: ?>
                    <span class="px-2 py-1 text-xs rounded" style="background:var(--dim);color:#fff">○ Déconnecté</span>
                    <?php endif; ?>
                </div>
                
                <div class="space-y-2 text-xs">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <strong style="color:var(--ink-soft)">URL:</strong>
                            <code style="color:var(--ink-soft)"><?= htmlspecialchars($ollamaDetails['url']) ?></code>
                        </div>
                        <div>
                            <strong style="color:var(--ink-soft)">Modèle configuré:</strong>
                            <code style="color:var(--ink-soft)"><?= htmlspecialchars($ollamaDetails['model']) ?></code>
                        </div>
                    </div>
                    
                    <?php if ($ollamaDetails['available']): ?>
                        <div class="mt-2 p-2 rounded border" style="background:var(--surface);border-color:var(--border)">
                            <strong style="color:var(--ink-soft)">Modèles installés:</strong> <?= count($ollamaDetails['models']) ?>
                            <?php if (!empty($ollamaDetails['models'])): ?>
                            <details class="mt-1">
                                <summary class="cursor-pointer hover:underline" style="color:var(--accent)">Voir la liste</summary>
                                <ul class="mt-1 ml-4 list-disc" style="color:var(--ink-soft)">
                                    <?php foreach (array_slice($ollamaDetails['models'], 0, 10) as $model): ?>
                                    <li class="font-mono text-xs"><?= htmlspecialchars($model) ?></li>
                                    <?php endforeach; ?>
                                    <?php if (count($ollamaDetails['models']) > 10): ?>
                                    <li style="color:var(--dim)">... et <?= count($ollamaDetails['models']) - 10 ?> autres</li>
                                    <?php endif; ?>
                                </ul>
                            </details>
                            <?php endif; ?>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-2 mt-2">
                            <div class="p-2 rounded border" style="background:var(--surface);<?= $ollamaDetails['has_llm'] ? 'border-color:var(--green)' : 'border-color:var(--amber)' ?>">
                                <strong style="color:var(--ink-soft)">Modèle LLM:</strong>
                                <?php if ($ollamaDetails['has_llm']): ?>
                                <span style="color:var(--green)">✓ Disponible</span>
                                <?php else: ?>
                                <span style="color:var(--amber)">⚠ Non trouvé</span>
                                <?php endif; ?>
                                <p class="text-xs mt-1" style="color:var(--dim)">Pour classification/chat</p>
                            </div>
                            <div class="p-2 rounded border" style="background:var(--surface);<?= $ollamaDetails['has_embedding'] ? 'border-color:var(--green)' : 'border-color:var(--amber)' ?>">
                                <strong style="color:var(--ink-soft)">Modèle Embedding:</strong>
                                <?php if ($ollamaDetails['has_embedding']): ?>
                                <span style="color:var(--green)">✓ Disponible</span>
                                <?php else: ?>
                                <span style="color:var(--amber)">⚠ Non trouvé</span>
                                <?php endif; ?>
                                <p class="text-xs mt-1" style="color:var(--dim)">Pour recherche sémantique</p>
                            </div>
                        </div>
                        
                        <?php
                        // Vérifier si le modèle configuré est installé
                        $modelInstalled = in_array($ollamaDetails['model'], $ollamaDetails['models']) 
                            || in_array($ollamaDetails['model'] . ':latest', $ollamaDetails['models']);
                        if (!$modelInstalled && $ollamaDetails['available']):
                        ?>
                        <div class="mt-2 p-2 border rounded text-xs" style="background:color-mix(in srgb,var(--amber) 12%,transparent);border-color:var(--amber)">
                            <strong style="color:var(--amber)">⚠ Modèle non installé:</strong>
                            <code style="color:var(--amber)"><?= htmlspecialchars($ollamaDetails['model']) ?></code>
                            <p class="mt-1" style="color:var(--amber)">
                                Installez-le avec: <code class="px-1 rounded" style="background:color-mix(in srgb,var(--amber) 18%,transparent);color:var(--amber)">ollama pull <?= htmlspecialchars($ollamaDetails['model']) ?></code>
                            </p>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="mt-2 p-2 rounded text-xs" style="background:var(--rail);color:var(--ink-soft)">
                            <strong>Ollama n'est pas accessible.</strong> Assurez-vous qu'Ollama est démarré et accessible à l'URL configurée.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Règles -->
            <div class="mb-4 p-4 border rounded-lg" style="background:var(--accent-soft);border-color:var(--accent)">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold" style="color:var(--ink-soft)">3️⃣ Règles d'Attribution</h3>
                    <span class="px-2 py-1 text-xs rounded" style="background:var(--accent);color:#fff">✓ Toujours actif</span>
                </div>
                <div class="text-xs" style="color:var(--ink-soft)">
                    <strong><?= $rulesCount ?> règles actives</strong> - Fallback garanti même sans IA
                    <a href="<?= url('/admin/attribution-rules') ?>" class="hover:underline ml-2" style="color:var(--accent)">Gérer les règles →</a>
                </div>
            </div>
            
            <div class="border rounded-lg p-4" style="background:var(--accent-soft);border-color:var(--accent)">
                <h3 class="text-sm font-medium mb-2" style="color:var(--ink)">Utilisation</h3>
                <ul class="text-xs space-y-1 list-disc list-inside" style="color:var(--ink-soft)">
                    <li>Classification automatique des documents</li>
                    <li>Extraction intelligente de métadonnées</li>
                    <li>Chat IA pour interroger vos documents</li>
                </ul>
            </div>
        </div>

        <!-- Section Indexation -->
        <div class="rounded-lg shadow p-6 mb-6" style="background:var(--surface)">
            <h2 class="text-lg font-semibold mb-4">⚙️ Paramètres d'indexation</h2>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">
                        Queues simultanées max
                    </label>
                    <input type="number" name="indexing_max_concurrent_queues" 
                           value="<?= htmlspecialchars($indexingMaxQueues) ?>"
                           min="1" max="10"
                           class="form-input">
                    <p class="text-xs mt-1" style="color:var(--dim)">1-10. Plus = plus rapide mais plus de charge</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">
                        Mémoire par worker (MB)
                    </label>
                    <input type="number" name="indexing_memory_limit" 
                           value="<?= htmlspecialchars($indexingMemoryLimit) ?>"
                           min="64" max="512"
                           class="form-input">
                    <p class="text-xs mt-1" style="color:var(--dim)">64-512 MB</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">
                        Pause entre fichiers (ms)
                    </label>
                    <input type="number" name="indexing_delay_between_files" 
                           value="<?= htmlspecialchars($indexingDelayFiles) ?>"
                           min="0" max="1000"
                           class="form-input">
                    <p class="text-xs mt-1" style="color:var(--dim)">0 = pas de pause, 50-100 recommandé</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">
                        Fichiers par batch
                    </label>
                    <input type="number" name="indexing_batch_size" 
                           value="<?= htmlspecialchars($indexingBatchSize) ?>"
                           min="5" max="100"
                           class="form-input">
                    <p class="text-xs mt-1" style="color:var(--dim)">Pause longue après ce nombre de fichiers</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">
                        Pause après batch (ms)
                    </label>
                    <input type="number" name="indexing_batch_pause" 
                           value="<?= htmlspecialchars($indexingBatchPause) ?>"
                           min="0" max="5000"
                           class="form-input">
                </div>
                
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" name="indexing_turbo_mode" value="1"
                               <?= $indexingTurboMode === '1' ? 'checked' : '' ?>
                               class="mr-2">
                        <span class="text-sm font-medium" style="color:var(--ink-soft)">Mode Turbo</span>
                    </label>
                    <p class="text-xs mt-1" style="color:var(--dim)">Ignore toutes les pauses (charge max)</p>
                </div>
            </div>
            
            <div class="mt-4 p-3 border rounded" style="background:var(--accent-soft);border-color:var(--accent)">
                <p class="text-xs" style="color:var(--ink-soft)">
                    <strong>💡 Astuce :</strong> Ces paramètres contrôlent l'indexation en arrière-plan. 
                    Réduisez les pauses pour une indexation plus rapide, mais augmentez-les si le serveur est surchargé.
                </p>
            </div>
        </div>

        <!-- Boutons -->
        <div class="flex items-center justify-end gap-4">
            <a href="<?= url('/admin') ?>" class="btn-secondary border px-4 py-2 rounded-lg">
                Annuler
            </a>
            <button type="submit" class="btn-primary px-6 py-2 rounded-lg">
                💾 Enregistrer les paramètres
            </button>
        </div>
    </form>
</div>
