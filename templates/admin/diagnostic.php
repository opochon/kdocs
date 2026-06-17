<?php
/**
 * K-Docs - Page de diagnostic système
 * Affiche le statut de tous les composants
 */
?>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Diagnostic Système</h1>
        <button onclick="refreshDiagnostic()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Actualiser
        </button>
    </div>

    <!-- CASCADE IA -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">CASCADE IA</h2>
        <p class="text-sm text-gray-500 mb-4">Ordre de priorité: Claude/Anthropic > Ollama > Règles</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <!-- Claude/Anthropic -->
            <div class="border rounded-lg p-4 <?= $aiStatus['claude']['available'] ? 'border-green-500 bg-green-50' : ($aiStatus['claude']['configured'] ? 'border-yellow-500 bg-yellow-50' : 'border-gray-300 bg-gray-50') ?>">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">Claude/Anthropic</span>
                    <?php if ($aiStatus['claude']['available']): ?>
                        <span class="px-2 py-1 text-xs bg-green-500 text-white rounded">ACTIF</span>
                    <?php elseif ($aiStatus['claude']['configured']): ?>
                        <span class="px-2 py-1 text-xs bg-yellow-500 text-white rounded">CONFIGURE</span>
                    <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-gray-400 text-white rounded">NON CONFIGURE</span>
                    <?php endif; ?>
                </div>
                <ul class="text-sm text-gray-600 space-y-1">
                    <li>Modèle: <?= htmlspecialchars($aiStatus['claude']['model'] ?? 'N/A') ?></li>
                    <?php if ($aiStatus['claude']['error']): ?>
                        <li class="text-red-600">Erreur: <?= htmlspecialchars($aiStatus['claude']['error']) ?></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Ollama -->
            <div class="border rounded-lg p-4 <?= $aiStatus['ollama']['available'] ? 'border-green-500 bg-green-50' : 'border-gray-300 bg-gray-50' ?>">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">Ollama (Local)</span>
                    <?php if ($aiStatus['ollama']['available']): ?>
                        <span class="px-2 py-1 text-xs bg-green-500 text-white rounded">CONNECTE</span>
                    <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-gray-400 text-white rounded">DECONNECTE</span>
                    <?php endif; ?>
                </div>
                <ul class="text-sm text-gray-600 space-y-1">
                    <li>URL: <?= htmlspecialchars($aiStatus['ollama']['url'] ?? 'N/A') ?></li>
                    <?php if (!empty($aiStatus['ollama']['models'])): ?>
                        <li>Modèles: <?= htmlspecialchars(implode(', ', $aiStatus['ollama']['models'])) ?></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Règles -->
            <div class="border rounded-lg p-4 border-green-500 bg-green-50">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">Règles</span>
                    <span class="px-2 py-1 text-xs bg-green-500 text-white rounded">TOUJOURS ACTIF</span>
                </div>
                <ul class="text-sm text-gray-600 space-y-1">
                    <li><?= $rulesCount ?> règles actives</li>
                    <li>Fallback garanti</li>
                </ul>
            </div>
        </div>

        <!-- Cascade actuelle -->
        <div class="bg-gray-100 rounded-lg p-4">
            <p class="font-semibold mb-2">Cascade actuelle:</p>
            <div class="flex items-center space-x-2">
                <?php
                $cascadeOrder = ['claude' => 'Claude', 'ollama' => 'Ollama', 'rules' => 'Règles'];
                $first = true;
                foreach ($cascadeOrder as $key => $label):
                    $isActive = ($key === 'rules') || ($key === 'claude' && $aiStatus['claude']['available']) || ($key === 'ollama' && $aiStatus['ollama']['available']);
                ?>
                    <?php if (!$first): ?>
                        <span class="text-gray-400">→</span>
                    <?php endif; ?>
                    <span class="px-3 py-1 rounded <?= $isActive ? 'bg-blue-500 text-white' : 'bg-gray-300 text-gray-500 line-through' ?>">
                        <?= $label ?>
                    </span>
                <?php
                    $first = false;
                endforeach;
                ?>
            </div>
            <?php if ($aiStatus['fallback_active'] ?? false): ?>
                <p class="text-sm text-yellow-600 mt-2">Mode fallback actif: Claude non disponible, Ollama utilisé.</p>
            <?php endif; ?>
        </div>

        <!-- Test rapide -->
        <div class="mt-4">
            <button onclick="testAI()" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700" id="testAiBtn">
                Tester la classification
            </button>
            <div id="aiTestResult" class="mt-2 hidden"></div>
        </div>
    </div>

    <!-- Training -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Training / Apprentissage</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="border rounded-lg p-4">
                <span class="text-sm text-gray-500">Status</span>
                <p class="text-lg font-semibold <?= $training['enabled'] ? 'text-green-600' : 'text-gray-400' ?>">
                    <?= $training['enabled'] ? 'Activé' : 'Désactivé' ?>
                </p>
            </div>
            <div class="border rounded-lg p-4">
                <span class="text-sm text-gray-500">Corrections stockées</span>
                <p class="text-lg font-semibold"><?= $training['corrections'] ?></p>
            </div>
            <div class="border rounded-lg p-4">
                <span class="text-sm text-gray-500">Seuil similarité</span>
                <p class="text-lg font-semibold"><?= ($training['min_similarity'] * 100) ?>%</p>
            </div>
        </div>
    </div>

    <!-- Embeddings -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Embeddings / Recherche sémantique</h2>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="border rounded-lg p-4">
                <span class="text-sm text-gray-500">Status</span>
                <p class="text-lg font-semibold <?= $embeddings['enabled'] ? 'text-green-600' : 'text-gray-400' ?>">
                    <?= $embeddings['enabled'] ? 'Activé' : 'Désactivé' ?>
                </p>
            </div>
            <div class="border rounded-lg p-4">
                <span class="text-sm text-gray-500">Provider</span>
                <p class="text-lg font-semibold"><?= ucfirst($embeddings['provider']) ?></p>
            </div>
            <div class="border rounded-lg p-4">
                <span class="text-sm text-gray-500">Dimensions</span>
                <p class="text-lg font-semibold"><?= $embeddings['dimensions'] ?></p>
            </div>
            <div class="border rounded-lg p-4">
                <span class="text-sm text-gray-500">Documents avec embedding</span>
                <p class="text-lg font-semibold"><?= $embeddings['docs_with_embedding'] ?> / <?= $embeddings['total_docs'] ?></p>
            </div>
        </div>
    </div>

    <!-- Outils externes -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Outils Externes</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Outil</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Status</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Chemin</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Version</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tools as $name => $tool): ?>
                    <tr class="border-t">
                        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($name) ?></td>
                        <td class="px-4 py-3">
                            <?php if ($tool['installed']): ?>
                                <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">Installé</span>
                            <?php else: ?>
                                <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">Non trouvé</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 font-mono"><?= htmlspecialchars($tool['path']) ?></td>
                        <td class="px-4 py-3 text-sm"><?= htmlspecialchars($tool['version'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Services -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Services</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- MySQL -->
            <div class="border rounded-lg p-4 <?= $services['mysql']['connected'] ? 'border-green-500' : 'border-red-500' ?>">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">MySQL/MariaDB</span>
                    <?php if ($services['mysql']['connected']): ?>
                        <span class="px-2 py-1 text-xs bg-green-500 text-white rounded">CONNECTE</span>
                    <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-red-500 text-white rounded">ERREUR</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm text-gray-600">Version: <?= htmlspecialchars($services['mysql']['version'] ?? 'N/A') ?></p>
            </div>

            <!-- OnlyOffice -->
            <div class="border rounded-lg p-4 <?= $services['onlyoffice']['status'] === 'connected' ? 'border-green-500' : ($services['onlyoffice']['status'] === 'disabled' ? 'border-gray-300' : 'border-red-500') ?>">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">OnlyOffice</span>
                    <?php if ($services['onlyoffice']['status'] === 'connected'): ?>
                        <span class="px-2 py-1 text-xs bg-green-500 text-white rounded">CONNECTE</span>
                    <?php elseif ($services['onlyoffice']['status'] === 'disabled'): ?>
                        <span class="px-2 py-1 text-xs bg-gray-400 text-white rounded">DESACTIVE</span>
                    <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-red-500 text-white rounded">ERREUR</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm text-gray-600">URL: <?= htmlspecialchars($services['onlyoffice']['url'] ?? 'N/A') ?></p>
            </div>

            <!-- Ollama -->
            <div class="border rounded-lg p-4 <?= $services['ollama']['connected'] ? 'border-green-500' : 'border-gray-300' ?>">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">Ollama</span>
                    <?php if ($services['ollama']['connected']): ?>
                        <span class="px-2 py-1 text-xs bg-green-500 text-white rounded">CONNECTE</span>
                    <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-gray-400 text-white rounded">DECONNECTE</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm text-gray-600">Modèles: <?= $services['ollama']['models_count'] ?? 0 ?></p>
            </div>
        </div>
    </div>

    <!-- Tests rapides -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Tests rapides</h2>
        <div class="flex flex-wrap gap-4">
            <a href="<?= url('/api/ai/status') ?>" target="_blank" class="px-4 py-2 bg-gray-100 rounded hover:bg-gray-200">
                API: /api/ai/status
            </a>
            <a href="<?= url('/api/documents?limit=5') ?>" target="_blank" class="px-4 py-2 bg-gray-100 rounded hover:bg-gray-200">
                API: /api/documents
            </a>
            <a href="<?= url('/api/search?q=test') ?>" target="_blank" class="px-4 py-2 bg-gray-100 rounded hover:bg-gray-200">
                API: /api/search
            </a>
        </div>
        <p class="text-sm text-gray-500 mt-4">
            Pour les tests complets: <code class="bg-gray-100 px-2 py-1 rounded">php tests/integration_tests.php --verbose</code>
        </p>
    </div>
</div>

<script>
function refreshDiagnostic() {
    window.location.reload();
}

async function testAI() {
    const btn = document.getElementById('testAiBtn');
    const resultDiv = document.getElementById('aiTestResult');

    btn.disabled = true;
    btn.textContent = 'Test en cours...';
    resultDiv.classList.add('hidden');

    try {
        const response = await fetch('<?= url('/api/ai/test') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });

        const data = await response.json();

        resultDiv.classList.remove('hidden');

        if (data.success && data.data) {
            resultDiv.className = 'mt-2 p-4 bg-green-100 rounded text-green-800';
            resultDiv.innerHTML = `
                <p><strong>Succès!</strong></p>
                <p>Provider: ${data.data.provider}</p>
                <p>Modèle: ${data.data.model}</p>
                <p>Temps: ${data.data.duration_ms}ms</p>
                <p>Réponse: ${data.data.response}</p>
            `;
        } else {
            resultDiv.className = 'mt-2 p-4 bg-red-100 rounded text-red-800';
            resultDiv.innerHTML = `<p><strong>Échec:</strong> ${data.message || 'Erreur inconnue'}</p>`;
        }
    } catch (error) {
        resultDiv.classList.remove('hidden');
        resultDiv.className = 'mt-2 p-4 bg-red-100 rounded text-red-800';
        resultDiv.innerHTML = `<p><strong>Erreur:</strong> ${error.message}</p>`;
    }

    btn.disabled = false;
    btn.textContent = 'Tester la classification';
}
</script>
