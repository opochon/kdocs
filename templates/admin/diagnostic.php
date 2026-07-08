<?php
/**
 * K-Docs - Page de diagnostic système
 * Affiche le statut de tous les composants
 */
?>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Diagnostic Système</h1>
        <button onclick="refreshDiagnostic()" class="btn btn-primary">
            Actualiser
        </button>
    </div>

    <!-- Connecteurs et plugins (registre unifié — lot P1) -->
    <?php
    $connHealth = $connectorsHealth ?? ['connectors' => [], 'plugins' => []];
    $statusChip = static function (string $status): string {
        return match ($status) {
            'available' => 'ds-chip ds-chip--green',
            'disabled' => 'ds-chip ds-chip--neutral',
            'blocked' => 'ds-chip ds-chip--amber',
            default => 'ds-chip ds-chip--amber',
        };
    };
    $statusLabel = static function (string $status): string {
        return match ($status) {
            'available' => 'DISPONIBLE',
            'disabled' => 'DÉSACTIVÉ',
            'blocked' => 'BLOQUÉ',
            'unavailable' => 'INDISPONIBLE',
            default => strtoupper($status),
        };
    };
    ?>
    <div class="ds-card rounded-lg shadow p-6">
        <h2 class="text-xl font-bold mb-2" style="color:var(--ink)">Connecteurs et plugins</h2>
        <p class="text-sm mb-4" style="color:var(--dim)">GED autonome — extensions activées par .env et health. Spec : docs/CONNECTEURS-PLUGINS.md</p>

        <h3 class="text-sm font-semibold mb-2" style="color:var(--ink-soft)">Ingest &amp; ERP</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 mb-6">
            <?php foreach ($connHealth['connectors'] ?? [] as $row): ?>
            <div class="border rounded-lg p-4" style="border-color:var(--border);background:var(--app-bg)">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <span class="font-semibold text-sm"><?= htmlspecialchars((string) ($row['label'] ?? $row['id'])) ?></span>
                    <span class="px-2 py-0.5 text-xs <?= $statusChip((string) ($row['status'] ?? '')) ?>">
                        <?= htmlspecialchars($statusLabel((string) ($row['status'] ?? ''))) ?>
                    </span>
                </div>
                <p class="text-xs mb-2" style="color:var(--dim)"><?= htmlspecialchars((string) ($row['description'] ?? '')) ?></p>
                <ul class="text-xs space-y-0.5" style="color:var(--ink-soft)">
                    <?php if (!empty($row['url'])): ?>
                        <li>URL : <?= htmlspecialchars((string) $row['url']) ?></li>
                    <?php endif; ?>
                    <?php if (!empty($row['path'])): ?>
                        <li>Chemin : <?= htmlspecialchars((string) $row['path']) ?></li>
                    <?php endif; ?>
                    <?php if (!empty($row['message'])): ?>
                        <li><?= htmlspecialchars((string) $row['message']) ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>

        <h3 class="text-sm font-semibold mb-2" style="color:var(--ink-soft)">Plugins métier</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <?php foreach ($connHealth['plugins'] ?? [] as $row): ?>
            <div class="border rounded-lg p-4" style="border-color:var(--border);background:var(--app-bg)">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <span class="font-semibold text-sm"><?= htmlspecialchars((string) ($row['label'] ?? $row['id'])) ?></span>
                    <span class="px-2 py-0.5 text-xs <?= $statusChip((string) ($row['status'] ?? '')) ?>">
                        <?= htmlspecialchars($statusLabel((string) ($row['status'] ?? ''))) ?>
                    </span>
                </div>
                <?php if (!empty($row['requires'])): ?>
                    <p class="text-xs" style="color:var(--dim)">Requiert : <?= htmlspecialchars(implode(', ', (array) $row['requires'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($row['blocked_by'])): ?>
                    <p class="text-xs mt-1" style="color:var(--amber)">Bloqué par : <?= htmlspecialchars(implode(', ', (array) $row['blocked_by'])) ?></p>
                <?php endif; ?>
                <p class="text-xs mt-1" style="color:var(--ink-soft)"><?= htmlspecialchars((string) ($row['message'] ?? '')) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="text-xs mt-3" style="color:var(--dim)">API JSON : <code>GET /api/admin/connectors/health</code></p>
    </div>

    <!-- CASCADE IA -->
    <div class="ds-card rounded-lg shadow p-6">
        <h2 class="text-xl font-bold mb-4" style="color:var(--ink)">CASCADE IA</h2>
        <p class="text-sm mb-4" style="color:var(--dim)">Ordre de priorité: Infomaniak (cloud CH) > Claude/Anthropic > Ollama > Règles</p>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <!-- Infomaniak AI Tools -->
            <div class="border rounded-lg p-4" style="<?= $aiStatus['infomaniak']['available'] ?? false ? 'border-color:var(--green);background:color-mix(in srgb,var(--green) 10%,transparent)' : (($aiStatus['infomaniak']['configured'] ?? false) ? 'border-color:var(--amber);background:color-mix(in srgb,var(--amber) 10%,transparent)' : 'border-color:var(--border);background:var(--app-bg)') ?>">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">Infomaniak AI</span>
                    <?php if ($aiStatus['infomaniak']['available'] ?? false): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">ACTIF</span>
                    <?php elseif ($aiStatus['infomaniak']['configured'] ?? false): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--amber">CONFIGURE</span>
                    <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--neutral">NON CONFIGURE</span>
                    <?php endif; ?>
                </div>
                <ul class="text-sm space-y-1" style="color:var(--ink-soft)">
                    <li>Modèle: <?= htmlspecialchars($aiStatus['infomaniak']['model'] ?? 'N/A') ?></li>
                    <li>Product ID: <?= ($aiStatus['infomaniak']['product_id_set'] ?? false) ? 'oui' : 'non' ?></li>
                </ul>
            </div>

            <!-- Claude/Anthropic -->
            <div class="border rounded-lg p-4" style="<?= $aiStatus['claude']['available'] ? 'border-color:var(--green);background:color-mix(in srgb,var(--green) 10%,transparent)' : ($aiStatus['claude']['configured'] ? 'border-color:var(--amber);background:color-mix(in srgb,var(--amber) 10%,transparent)' : 'border-color:var(--border);background:var(--app-bg)') ?>">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">Claude/Anthropic</span>
                    <?php if ($aiStatus['claude']['available']): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">ACTIF</span>
                    <?php elseif ($aiStatus['claude']['configured']): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--amber">CONFIGURE</span>
                    <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--neutral">NON CONFIGURE</span>
                    <?php endif; ?>
                </div>
                <ul class="text-sm space-y-1" style="color:var(--ink-soft)">
                    <li>Modèle: <?= htmlspecialchars($aiStatus['claude']['model'] ?? 'N/A') ?></li>
                    <?php if (!empty($aiStatus['claude']['error'])): ?>
                        <li style="color:var(--red)">Erreur: <?= htmlspecialchars($aiStatus['claude']['error']) ?></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Ollama -->
            <div class="border rounded-lg p-4" style="<?= $aiStatus['ollama']['available'] ? 'border-color:var(--green);background:color-mix(in srgb,var(--green) 10%,transparent)' : 'border-color:var(--border);background:var(--app-bg)' ?>">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">Ollama (Local)</span>
                    <?php if ($aiStatus['ollama']['available']): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">CONNECTE</span>
                    <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--neutral">DECONNECTE</span>
                    <?php endif; ?>
                </div>
                <ul class="text-sm space-y-1" style="color:var(--ink-soft)">
                    <li>URL: <?= htmlspecialchars($aiStatus['ollama']['url'] ?? 'N/A') ?></li>
                    <?php if (!empty($aiStatus['ollama']['models'])): ?>
                        <li>Modèles: <?= htmlspecialchars(implode(', ', $aiStatus['ollama']['models'])) ?></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Règles -->
            <div class="border rounded-lg p-4" style="border-color:var(--green);background:color-mix(in srgb,var(--green) 10%,transparent)">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">Règles</span>
                    <span class="px-2 py-1 text-xs ds-chip ds-chip--green">TOUJOURS ACTIF</span>
                </div>
                <ul class="text-sm space-y-1" style="color:var(--ink-soft)">
                    <li><?= $rulesCount ?> règles actives</li>
                    <li>Fallback garanti</li>
                </ul>
            </div>
        </div>

        <!-- Cascade actuelle -->
        <div class="rounded-lg p-4" style="background:var(--app-bg)">
            <p class="font-semibold mb-2">Cascade actuelle:</p>
            <div class="flex items-center space-x-2">
                <?php
                $cascadeOrder = ['infomaniak' => 'Infomaniak', 'claude' => 'Claude', 'ollama' => 'Ollama', 'rules' => 'Règles'];
                $first = true;
                foreach ($cascadeOrder as $key => $label):
                    $isActive = ($key === 'rules')
                        || ($key === 'infomaniak' && ($aiStatus['infomaniak']['available'] ?? false))
                        || ($key === 'claude' && $aiStatus['claude']['available'])
                        || ($key === 'ollama' && $aiStatus['ollama']['available']);
                ?>
                    <?php if (!$first): ?>
                        <span style="color:var(--dim)">→</span>
                    <?php endif; ?>
                    <span class="px-3 py-1 <?= $isActive ? 'ds-chip ds-chip--accent' : 'ds-chip ds-chip--neutral line-through' ?>">
                        <?= $label ?>
                    </span>
                <?php
                    $first = false;
                endforeach;
                ?>
            </div>
            <?php if ($aiStatus['fallback_active'] ?? false): ?>
                <p class="text-sm mt-2" style="color:var(--amber)">Mode fallback actif: Claude non disponible, Ollama utilisé.</p>
            <?php endif; ?>
        </div>

        <!-- Test rapide -->
        <div class="mt-4">
            <button onclick="testAI()" class="btn btn-secondary" id="testAiBtn">
                Tester la classification
            </button>
            <div id="aiTestResult" class="mt-2 hidden"></div>
        </div>
    </div>

    <!-- Ingest (CMD v4 + natif GED) -->
    <?php $ingest = $ingestEngine ?? []; ?>
    <?php $v4 = $ingest['cmd_v4'] ?? []; ?>
    <div class="ds-card rounded-lg shadow p-6">
        <h2 class="text-xl font-bold mb-4" style="color:var(--ink)">Ingest (CMD v4 + natif GED)</h2>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
            <div class="border rounded-lg p-4" style="border-color:var(--border);background:var(--app-bg)">
                <span class="text-sm" style="color:var(--dim)">Moteur actif</span>
                <p class="text-lg font-semibold"><?= htmlspecialchars((string) ($ingest['active_engine'] ?? 'native')) ?></p>
            </div>
            <div class="border rounded-lg p-4" style="<?= !empty($v4['v4_available']) ? 'border-color:var(--green);background:color-mix(in srgb,var(--green) 10%,transparent)' : 'border-color:var(--amber);background:color-mix(in srgb,var(--amber) 10%,transparent)' ?>">
                <span class="text-sm" style="color:var(--dim)">CMD v4</span>
                <p class="text-lg font-semibold"><?= !empty($v4['v4_available']) ? 'OK' : 'Indisponible' ?></p>
                <p class="text-xs" style="color:var(--dim)"><?= htmlspecialchars((string) ($v4['api_url'] ?? '')) ?></p>
            </div>
            <div class="border rounded-lg p-4">
                <span class="text-sm" style="color:var(--dim)">Routage factures</span>
                <p class="text-lg font-semibold"><?= !empty($v4['invoice_routing_available']) ? 'Actif' : 'Inactif' ?></p>
            </div>
            <div class="border rounded-lg p-4">
                <span class="text-sm" style="color:var(--dim)">Version CMD v4</span>
                <p class="text-lg font-semibold"><?= htmlspecialchars((string) ($v4['version'] ?? 'N/A')) ?></p>
            </div>
        </div>
        <ul class="text-sm space-y-1" style="color:var(--ink-soft)">
            <li>Chemin install : <?= htmlspecialchars((string) ($v4['install_path'] ?? 'non configuré')) ?></li>
            <li>API joignable : <?= !empty($v4['remote_ok']) ? 'oui' : 'non' ?></li>
        </ul>
        <p class="text-xs mt-3" style="color:var(--dim)">Connecteur ClearMyDocs v3 retiré (ancienne version). Voir docs/CMD-V4-CONNECTOR.md</p>
    </div>

    <!-- Training -->
    <div class="ds-card rounded-lg shadow p-6">
        <h2 class="text-xl font-bold mb-4" style="color:var(--ink)">Training / Apprentissage</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="border rounded-lg p-4">
                <span class="text-sm" style="color:var(--dim)">Status</span>
                <p class="text-lg font-semibold" style="<?= $training['enabled'] ? 'color:var(--green)' : 'color:var(--dim)' ?>">
                    <?= $training['enabled'] ? 'Activé' : 'Désactivé' ?>
                </p>
            </div>
            <div class="border rounded-lg p-4">
                <span class="text-sm" style="color:var(--dim)">Corrections stockées</span>
                <p class="text-lg font-semibold"><?= $training['corrections'] ?></p>
            </div>
            <div class="border rounded-lg p-4">
                <span class="text-sm" style="color:var(--dim)">Seuil similarité</span>
                <p class="text-lg font-semibold"><?= ($training['min_similarity'] * 100) ?>%</p>
            </div>
        </div>
    </div>

    <!-- Embeddings — visible si infra Qdrant activée -->
    <?php if (isQdrantUiEnabled()): ?>
    <div class="ds-card rounded-lg shadow p-6">
        <h2 class="text-xl font-bold mb-4" style="color:var(--ink)">Embeddings / Recherche sémantique</h2>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="border rounded-lg p-4">
                <span class="text-sm" style="color:var(--dim)">Status</span>
                <p class="text-lg font-semibold" style="<?= $embeddings['enabled'] ? 'color:var(--green)' : 'color:var(--dim)' ?>">
                    <?= $embeddings['enabled'] ? 'Activé' : 'Désactivé' ?>
                </p>
            </div>
            <div class="border rounded-lg p-4">
                <span class="text-sm" style="color:var(--dim)">Provider</span>
                <p class="text-lg font-semibold"><?= ucfirst($embeddings['provider']) ?></p>
            </div>
            <div class="border rounded-lg p-4">
                <span class="text-sm" style="color:var(--dim)">Dimensions</span>
                <p class="text-lg font-semibold"><?= $embeddings['dimensions'] ?></p>
            </div>
            <div class="border rounded-lg p-4">
                <span class="text-sm" style="color:var(--dim)">Documents avec embedding</span>
                <p class="text-lg font-semibold"><?= $embeddings['docs_with_embedding'] ?> / <?= $embeddings['total_docs'] ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Outils externes -->
    <div class="ds-card rounded-lg shadow p-6">
        <h2 class="text-xl font-bold mb-4" style="color:var(--ink)">Outils Externes</h2>
        <div class="overflow-x-auto">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Outil</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Status</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Chemin</th>
                        <th class="px-4 py-2 text-left text-sm font-semibold">Version</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tools as $name => $tool): ?>
                    <tr class="border-t">
                        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($name) ?></td>
                        <td class="px-4 py-3">
                            <?php if ($tool['installed']): ?>
                                <span class="px-2 py-1 text-xs ds-chip ds-chip--green">Installé</span>
                            <?php else: ?>
                                <span class="px-2 py-1 text-xs ds-chip ds-chip--red">Non trouvé</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm font-mono" style="color:var(--ink-soft)"><?= htmlspecialchars($tool['path']) ?></td>
                        <td class="px-4 py-3 text-sm"><?= htmlspecialchars($tool['version'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Services -->
    <div class="ds-card rounded-lg shadow p-6">
        <h2 class="text-xl font-bold mb-4" style="color:var(--ink)">Services</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- MySQL -->
            <div class="border rounded-lg p-4" style="<?= $services['mysql']['connected'] ? 'border-color:var(--green)' : 'border-color:var(--red)' ?>">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">MySQL/MariaDB</span>
                    <?php if ($services['mysql']['connected']): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">CONNECTE</span>
                    <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--red">ERREUR</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm" style="color:var(--ink-soft)">Version: <?= htmlspecialchars($services['mysql']['version'] ?? 'N/A') ?></p>
            </div>

            <!-- OnlyOffice -->
            <div class="border rounded-lg p-4" style="<?= $services['onlyoffice']['status'] === 'connected' ? 'border-color:var(--green)' : ($services['onlyoffice']['status'] === 'disabled' ? 'border-color:var(--border)' : 'border-color:var(--red)') ?>">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">OnlyOffice</span>
                    <?php if ($services['onlyoffice']['status'] === 'connected'): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">CONNECTE</span>
                    <?php elseif ($services['onlyoffice']['status'] === 'disabled'): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--neutral">DESACTIVE</span>
                    <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--red">ERREUR</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm" style="color:var(--ink-soft)">URL: <?= htmlspecialchars($services['onlyoffice']['url'] ?? 'N/A') ?></p>
            </div>

            <!-- Ollama -->
            <div class="border rounded-lg p-4" style="<?= $services['ollama']['connected'] ? 'border-color:var(--green)' : 'border-color:var(--border)' ?>">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold">Ollama</span>
                    <?php if ($services['ollama']['connected']): ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--green">CONNECTE</span>
                    <?php else: ?>
                        <span class="px-2 py-1 text-xs ds-chip ds-chip--neutral">DECONNECTE</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm" style="color:var(--ink-soft)">Modèles: <?= $services['ollama']['models_count'] ?? 0 ?></p>
            </div>
        </div>
    </div>

    <!-- Tests rapides -->
    <div class="ds-card rounded-lg shadow p-6">
        <h2 class="text-xl font-bold mb-4" style="color:var(--ink)">Tests rapides</h2>
        <div class="flex flex-wrap gap-4">
            <a href="<?= url('/api/ai/status') ?>" target="_blank" class="btn btn-secondary">
                API: /api/ai/status
            </a>
            <a href="<?= url('/api/documents?limit=5') ?>" target="_blank" class="btn btn-secondary">
                API: /api/documents
            </a>
            <a href="<?= url('/api/search?q=test') ?>" target="_blank" class="btn btn-secondary">
                API: /api/search
            </a>
        </div>
        <p class="text-sm mt-4" style="color:var(--dim)">
            Pour les tests complets: <code class="px-2 py-1 rounded" style="background:var(--hover)">php tests/integration_tests.php --verbose</code>
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
            resultDiv.className = 'mt-2 p-4 rounded'; resultDiv.style.background = 'color-mix(in srgb, var(--green) 14%, transparent)'; resultDiv.style.color = 'var(--green)';
            resultDiv.innerHTML = `
                <p><strong>Succès!</strong></p>
                <p>Provider: ${data.data.provider}</p>
                <p>Modèle: ${data.data.model}</p>
                <p>Temps: ${data.data.duration_ms}ms</p>
                <p>Réponse: ${data.data.response}</p>
            `;
        } else {
            resultDiv.className = 'mt-2 p-4 rounded'; resultDiv.style.background = 'color-mix(in srgb, var(--red) 14%, transparent)'; resultDiv.style.color = 'var(--red)';
            resultDiv.innerHTML = `<p><strong>Échec:</strong> ${data.message || 'Erreur inconnue'}</p>`;
        }
    } catch (error) {
        resultDiv.classList.remove('hidden');
        resultDiv.className = 'mt-2 p-4 rounded'; resultDiv.style.background = 'color-mix(in srgb, var(--red) 14%, transparent)'; resultDiv.style.color = 'var(--red)';
        resultDiv.innerHTML = `<p><strong>Erreur:</strong> ${error.message}</p>`;
    }

    btn.disabled = false;
    btn.textContent = 'Tester la classification';
}
</script>
