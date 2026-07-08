<?php
/**
 * Template: Tableau de bord d'indexation
 * Index les documents de storage/documents pour une recherche optimale
 * @var array $status
 * @var array $logs
 * @var array $settings
 */
$progress = $status['progress'] ?? [];
$isRunning = $status['is_running'] ?? false;
?>

<div class="p-6 max-w-6xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-semibold" style="color:var(--ink)">Indexation</h1>
            <p class="text-sm mt-1" style="color:var(--dim)">Index les documents de storage/documents pour une recherche optimale</p>
        </div>

        <div class="flex gap-3">
            <button onclick="startIndexing()" id="btn-index" class="btn btn-primary disabled:opacity-50 disabled:cursor-not-allowed" <?= $isRunning ? 'disabled' : '' ?>>
                <svg class="w-4 h-4 <?= $isRunning ? 'animate-spin' : '' ?>" id="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                <span id="btn-text"><?= $isRunning ? 'Indexation en cours...' : 'Indexer maintenant' ?></span>
            </button>

            <button onclick="stopIndexing()" id="btn-stop" class="btn btn-danger <?= $isRunning ? '' : 'hidden' ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
                Arreter
            </button>
        </div>
    </div>

    <!-- Barre de progression -->
    <div id="progress-container" class="mb-6 <?= $isRunning ? '' : 'hidden' ?>">
        <div class="ds-card rounded-lg p-4">
            <div class="flex justify-between items-center mb-2">
                <span class="text-sm font-medium" style="color:var(--ink-soft)">Progression</span>
                <span class="text-sm" style="color:var(--dim)" id="progress-percent"><?= ($progress['percent'] ?? 0) ?>%</span>
            </div>
            <div class="w-full rounded-full h-3" style="background:var(--hover)">
                <div class="h-3 rounded-full transition-all duration-300" id="progress-bar" style="width: <?= ($progress['percent'] ?? 0) ?>%; background:var(--accent)"></div>
            </div>
            <div class="mt-2 flex justify-between text-xs" style="color:var(--dim)">
                <span id="progress-current"><?= ($progress['current_item'] ?? '') ?></span>
                <span id="progress-stats">
                    <?php if (!empty($progress['stats'])): ?>
                        <?= $progress['stats']['folders'] ?? 0 ?> dossiers, <?= $progress['stats']['files'] ?? 0 ?> fichiers
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Message de resultat -->
    <div id="result-message" class="mb-6 hidden">
        <div class="rounded-lg p-4" id="result-box">
            <p id="result-text"></p>
        </div>
    </div>

    <!-- Statistiques -->
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
        <div class="ds-card rounded-lg p-4">
            <p class="text-sm" style="color:var(--dim)">Documents indexes</p>
            <p class="text-2xl font-semibold" style="color:var(--ink)" id="stat-documents"><?= number_format($status['stats']['total_documents'] ?? 0) ?></p>
        </div>
        <div class="ds-card rounded-lg p-4">
            <p class="text-sm" style="color:var(--dim)">Dossiers</p>
            <p class="text-2xl font-semibold" style="color:var(--ink)" id="stat-folders"><?= number_format($status['stats']['total_folders'] ?? 0) ?></p>
        </div>
        <div class="ds-card rounded-lg p-4">
            <p class="text-sm" style="color:var(--dim)">Statut</p>
            <p class="text-lg font-medium" style="<?= $isRunning ? 'color:var(--amber)' : 'color:var(--green)' ?>" id="stat-status">
                <?= $isRunning ? 'Indexation en cours...' : 'Pret' ?>
            </p>
        </div>
    </div>

    <!-- Parametres d'indexation automatique -->
    <div class="ds-card rounded-lg mb-6">
        <div class="px-4 py-3 border-b">
            <h2 class="font-medium" style="color:var(--ink)">Parametres d'indexation automatique</h2>
        </div>
        <div class="p-4">
            <form id="settings-form" class="flex flex-wrap items-end gap-4">
                <div class="flex items-center gap-3">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" id="auto-enabled" class="sr-only peer" <?= ($settings['auto_enabled'] ?? false) ? 'checked' : '' ?>>
                        <div class="idx-switch w-11 h-6 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:border after:rounded-full after:h-5 after:w-5 after:transition-all"></div>
                    </label>
                    <span class="text-sm" style="color:var(--ink-soft)">Indexation automatique</span>
                </div>

                <div class="flex items-center gap-2">
                    <label for="interval" class="text-sm" style="color:var(--ink-soft)">Intervalle:</label>
                    <select id="interval" class="rounded-lg text-sm">
                        <?php
                        $intervals = [5 => '5 minutes', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 heure', 120 => '2 heures', 360 => '6 heures', 720 => '12 heures', 1440 => '24 heures'];
                        foreach ($intervals as $value => $label):
                        ?>
                        <option value="<?= $value ?>" <?= ($settings['interval_minutes'] ?? 60) == $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-secondary text-sm">
                    Sauvegarder
                </button>

                <span id="settings-status" class="text-sm hidden" style="color:var(--green)">Sauvegarde!</span>
            </form>

            <?php if (!empty($settings['last_run'])): ?>
            <p class="text-xs mt-3" style="color:var(--dim)">
                Derniere execution: <?= date('d/m/Y H:i:s', $settings['last_run']) ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Info -->
    <div class="border rounded-lg p-4 mb-6" style="border-color:color-mix(in srgb,var(--accent) 35%,var(--border));background:var(--accent-soft)">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 mt-0.5" style="color:var(--accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <p class="font-medium" style="color:var(--ink)">Indexation des documents</p>
                <p class="text-sm mt-1" style="color:var(--ink-soft)">
                    L'indexation parcourt le dossier <code class="px-1 rounded" style="background:color-mix(in srgb,var(--accent) 15%,transparent)">storage/documents</code> et met a jour la base de donnees
                    pour permettre une recherche rapide et efficace. Le processus s'execute en arriere-plan.
                </p>
            </div>
        </div>
    </div>

    <!-- Moteur Sémantique / Embeddings — visible si infra Qdrant activée -->
    <?php if (isQdrantUiEnabled()): ?>
    <?php
    $semantic = $semanticInfo ?? [];
    $modelInfo = $semantic['model_info'] ?? [];
    $stats = $semantic['statistics'] ?? [];
    ?>
    <div class="ds-card rounded-lg mb-6">
        <div class="px-4 py-3 border-b flex items-center justify-between">
            <h2 class="font-medium" style="color:var(--ink)">🔮 Moteur Sémantique / Recherche par Embeddings</h2>
            <?php if ($semantic['enabled'] ?? false): ?>
            <span class="px-2 py-1 text-xs ds-chip ds-chip--green">✓ Activé</span>
            <?php else: ?>
            <span class="px-2 py-1 text-xs ds-chip ds-chip--neutral">○ Désactivé</span>
            <?php endif; ?>
        </div>
        <div class="p-4">
            <?php if ($semantic['enabled'] ?? false): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <!-- Provider & Modèle -->
                    <div class="border rounded-lg p-3" style="border-color:var(--border)">
                        <div class="text-xs mb-1" style="color:var(--dim)">Provider</div>
                        <div class="text-sm font-semibold" style="color:var(--ink)">
                            <?= ucfirst($modelInfo['provider'] ?? 'unknown') ?>
                            <?php if ($modelInfo['provider'] === 'ollama' || $modelInfo['provider'] === 'local'): ?>
                            <span class="text-xs" style="color:var(--dim)">(Local)</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-xs mt-2" style="color:var(--dim)">Modèle</div>
                        <code class="text-xs" style="color:var(--ink-soft)"><?= htmlspecialchars($modelInfo['model'] ?? 'N/A') ?></code>
                        <div class="text-xs mt-2" style="color:var(--dim)">Dimensions</div>
                        <div class="text-sm font-semibold" style="color:var(--ink)"><?= $modelInfo['dimensions'] ?? 'N/A' ?></div>
                        <?php if (!empty($modelInfo['ollama_url'])): ?>
                        <div class="text-xs mt-2" style="color:var(--dim)">URL Ollama</div>
                        <code class="text-xs" style="color:var(--ink-soft)"><?= htmlspecialchars($modelInfo['ollama_url']) ?></code>
                        <?php endif; ?>
                    </div>

                    <!-- Statistiques -->
                    <div class="border rounded-lg p-3" style="border-color:var(--border)">
                        <div class="text-xs mb-2" style="color:var(--dim)">Statistiques</div>
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span style="color:var(--ink-soft)">Documents avec embedding:</span>
                                <span class="font-semibold" style="color:var(--ink)">
                                    <?= number_format($stats['completed'] ?? 0) ?> / <?= number_format($stats['total_documents'] ?? 0) ?>
                                </span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span style="color:var(--ink-soft)">En attente:</span>
                                <span class="font-semibold" style="color:var(--amber)"><?= number_format($stats['pending'] ?? 0) ?></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span style="color:var(--ink-soft)">Échecs:</span>
                                <span class="font-semibold" style="color:var(--red)"><?= number_format($stats['failed'] ?? 0) ?></span>
                            </div>
                            <?php if (!empty($stats['recent_activity'])): ?>
                            <div class="mt-3 pt-2 border-t">
                                <div class="text-xs mb-1" style="color:var(--dim)">Activité 24h</div>
                                <?php foreach ($stats['recent_activity'] as $activity): ?>
                                <div class="text-xs" style="color:var(--ink-soft)">
                                    <?= htmlspecialchars($activity['action']) ?>:
                                    <?= number_format($activity['count'] ?? 0) ?> opérations
                                    <?php if (!empty($activity['total_tokens'])): ?>
                                    (<?= number_format($activity['total_tokens']) ?> tokens)
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="text-xs border rounded p-2" style="border-color:color-mix(in srgb,var(--accent) 35%,var(--border));background:var(--accent-soft);color:var(--ink-soft)">
                    <strong>💡 Recherche sémantique:</strong> Permet de rechercher par sens et contexte, pas seulement par mots-clés.
                    Les embeddings sont générés automatiquement lors de l'indexation si le moteur est activé.
                    <br><span style="color:var(--ink-soft)">Configuration: <code>config/config.php</code> section <code>embeddings</code></span>
                </div>
            <?php else: ?>
                <div class="text-sm" style="color:var(--ink-soft)">
                    <p class="mb-2">Le moteur sémantique est désactivé. Pour l'activer:</p>
                    <ol class="list-decimal list-inside space-y-1 ml-2">
                        <li>Configurez les embeddings dans <code class="px-1 rounded" style="background:var(--hover)">config/config.php</code></li>
                        <li>Assurez-vous qu'Ollama est démarré (pour embeddings locaux)</li>
                        <li>Installez un modèle d'embedding: <code class="px-1 rounded" style="background:var(--hover)">ollama pull nomic-embed-text</code></li>
                    </ol>
                    <p class="mt-2 text-xs" style="color:var(--dim)">
                        La recherche fonctionne toujours avec FULLTEXT MySQL, mais sans recherche sémantique.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Logs recents -->
    <div class="ds-card rounded-lg">
        <div class="px-4 py-3 border-b flex justify-between items-center">
            <h2 class="font-medium" style="color:var(--ink)">
                Logs recents
                <span id="logs-updating" class="text-xs ml-2 hidden" style="color:var(--dim)">(mise a jour...)</span>
            </h2>
            <div class="flex gap-2 items-center">
                <label class="flex items-center gap-2 text-sm" style="color:var(--ink-soft)">
                    <input type="checkbox" id="auto-refresh" checked class="rounded" style="accent-color:var(--accent)">
                    Auto-refresh
                </label>
                <button onclick="refreshLogs()" class="text-sm hover:underline" style="color:var(--accent)">
                    Rafraichir
                </button>
                <button onclick="clearLogs()" class="text-sm hover:underline" style="color:var(--red)">
                    Effacer
                </button>
            </div>
        </div>
        <div class="max-h-80 overflow-y-auto" id="logs-container">
            <?php if (empty($logs)): ?>
                <p class="p-4 text-center text-sm" style="color:var(--dim)">Aucun log recent</p>
            <?php else: ?>
                <table class="ds-table">
                    <tbody id="logs-body">
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="px-4 py-2 whitespace-nowrap text-xs" style="color:var(--dim)"><?= htmlspecialchars($log['timestamp']) ?></td>
                            <td class="px-2 py-2">
                                <?php
                                $levelColors = [
                                    'INFO' => 'ds-chip--accent',
                                    'WARNING' => 'ds-chip--amber',
                                    'ERROR' => 'ds-chip--red',
                                ];
                                $color = $levelColors[$log['level']] ?? 'ds-chip--neutral';
                                ?>
                                <span class="px-2 py-0.5 text-xs ds-chip <?= $color ?>"><?= $log['level'] ?></span>
                            </td>
                            <td class="px-4 py-2 text-xs" style="color:var(--ink-soft)"><?= htmlspecialchars($log['message']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
let refreshInterval = null;
let isRunning = <?= $isRunning ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('auto-refresh').checked) {
        startAutoRefresh();
    }
});

document.getElementById('auto-refresh').addEventListener('change', function() {
    if (this.checked) {
        startAutoRefresh();
    } else {
        stopAutoRefresh();
    }
});

function startAutoRefresh() {
    if (refreshInterval) clearInterval(refreshInterval);
    refreshInterval = setInterval(function() {
        refreshStatus();
        refreshLogs();
    }, 2000);
}

function stopAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
}

function startIndexing() {
    const btn = document.getElementById('btn-index');
    const btnText = document.getElementById('btn-text');
    const btnIcon = document.getElementById('btn-icon');
    const btnStop = document.getElementById('btn-stop');
    const progressContainer = document.getElementById('progress-container');
    const resultDiv = document.getElementById('result-message');

    btn.disabled = true;
    btnText.textContent = 'Demarrage...';
    btnIcon.classList.add('animate-spin');
    resultDiv.classList.add('hidden');

    fetch('<?= url('/admin/indexing/start') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            isRunning = true;
            btnText.textContent = 'Indexation en cours...';
            btnStop.classList.remove('hidden');
            progressContainer.classList.remove('hidden');
            updateStatusDisplay(true);

            // Demarrer le polling rapide pour la progression
            if (!refreshInterval) startAutoRefresh();
        } else {
            btn.disabled = false;
            btnText.textContent = 'Indexer maintenant';
            btnIcon.classList.remove('animate-spin');
            showResult(false, data.error || 'Erreur');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btnText.textContent = 'Indexer maintenant';
        btnIcon.classList.remove('animate-spin');
        showResult(false, 'Erreur: ' + err);
    });
}

function stopIndexing() {
    fetch('<?= url('/admin/indexing/stop') ?>', {method: 'POST'})
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            isRunning = false;
            updateStatusDisplay(false);
            document.getElementById('progress-container').classList.add('hidden');
            document.getElementById('btn-stop').classList.add('hidden');
            showResult(true, 'Indexation arretee');
        }
    });
}

function refreshStatus() {
    fetch('<?= url('/admin/indexing/status') ?>')
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const status = data.status;
            const progress = status.progress || {};

            // Mettre a jour les stats
            document.getElementById('stat-documents').textContent = (status.stats.total_documents || 0).toLocaleString();
            document.getElementById('stat-folders').textContent = (status.stats.total_folders || 0).toLocaleString();

            // Mettre a jour le statut
            const wasRunning = isRunning;
            isRunning = status.is_running;
            updateStatusDisplay(isRunning);

            // Mettre a jour la progression
            if (isRunning && progress.status === 'running') {
                document.getElementById('progress-container').classList.remove('hidden');
                document.getElementById('btn-stop').classList.remove('hidden');
                document.getElementById('progress-percent').textContent = (progress.percent || 0) + '%';
                document.getElementById('progress-bar').style.width = (progress.percent || 0) + '%';
                document.getElementById('progress-current').textContent = progress.current_item || '';

                if (progress.stats) {
                    document.getElementById('progress-stats').textContent =
                        (progress.stats.folders || 0) + ' dossiers, ' + (progress.stats.files || 0) + ' fichiers';
                }
            } else if (progress.status === 'completed') {
                document.getElementById('progress-container').classList.add('hidden');
                document.getElementById('btn-stop').classList.add('hidden');

                if (wasRunning) {
                    const stats = progress.stats || {};
                    showResult(true, 'Indexation terminee: ' +
                        (stats.folders || 0) + ' dossiers, ' +
                        (stats.files || 0) + ' fichiers (' +
                        (stats.new || 0) + ' nouveaux, ' +
                        (stats.updated || 0) + ' mis a jour)');
                }
            } else if (progress.status === 'stale' || progress.status === 'error') {
                document.getElementById('progress-container').classList.add('hidden');
                document.getElementById('btn-stop').classList.add('hidden');
                if (progress.error) {
                    showResult(false, 'Erreur: ' + progress.error);
                }
            }
        }
    });
}

function updateStatusDisplay(running) {
    const btn = document.getElementById('btn-index');
    const btnText = document.getElementById('btn-text');
    const btnIcon = document.getElementById('btn-icon');
    const statusEl = document.getElementById('stat-status');

    btn.disabled = running;

    if (running) {
        btnText.textContent = 'Indexation en cours...';
        btnIcon.classList.add('animate-spin');
        statusEl.textContent = 'Indexation en cours...';
        statusEl.className = 'text-lg font-medium';
        statusEl.style.color = 'var(--amber)';
    } else {
        btnText.textContent = 'Indexer maintenant';
        btnIcon.classList.remove('animate-spin');
        statusEl.textContent = 'Pret';
        statusEl.className = 'text-lg font-medium';
        statusEl.style.color = 'var(--green)';
    }
}

function showResult(success, message) {
    const resultDiv = document.getElementById('result-message');
    const resultBox = document.getElementById('result-box');
    const resultText = document.getElementById('result-text');

    resultDiv.classList.remove('hidden');

    if (success) {
        resultBox.className = 'rounded-lg p-4 border';
        resultBox.style.cssText = 'border-color:color-mix(in srgb,var(--green) 35%,var(--border));background:color-mix(in srgb,var(--green) 10%,transparent)';
        resultText.style.color = 'var(--green)';
    } else {
        resultBox.className = 'rounded-lg p-4 border';
        resultBox.style.cssText = 'border-color:color-mix(in srgb,var(--red) 35%,var(--border));background:color-mix(in srgb,var(--red) 10%,transparent)';
        resultText.style.color = 'var(--red)';
    }
    resultText.textContent = message;
}

function refreshLogs() {
    const indicator = document.getElementById('logs-updating');
    indicator.classList.remove('hidden');

    fetch('<?= url('/admin/indexing/logs') ?>?limit=30')
    .then(r => r.json())
    .then(data => {
        indicator.classList.add('hidden');

        if (data.success && data.logs) {
            const container = document.getElementById('logs-container');

            if (data.logs.length === 0) {
                container.innerHTML = '<p class="p-4 text-center text-sm" style="color:var(--dim)">Aucun log recent</p>';
                return;
            }

            container.innerHTML = `<table class="ds-table"><tbody id="logs-body"></tbody></table>`;
            const logsBody = document.getElementById('logs-body');

            logsBody.innerHTML = data.logs.map(log => {
                const colors = {
                    'INFO': 'ds-chip--accent',
                    'WARNING': 'ds-chip--amber',
                    'ERROR': 'ds-chip--red'
                };
                const color = colors[log.level] || 'ds-chip--neutral';

                return `<tr>
                    <td class="px-4 py-2 whitespace-nowrap text-xs" style="color:var(--dim)">${escapeHtml(log.timestamp)}</td>
                    <td class="px-2 py-2"><span class="px-2 py-0.5 text-xs ds-chip ${color}">${log.level}</span></td>
                    <td class="px-4 py-2 text-xs" style="color:var(--ink-soft)">${escapeHtml(log.message)}</td>
                </tr>`;
            }).join('');
        }
    })
    .catch(() => {
        indicator.classList.add('hidden');
    });
}

function clearLogs() {
    if (!confirm('Effacer tous les logs d\'aujourd\'hui ?')) return;

    fetch('<?= url('/admin/indexing/clear-logs') ?>', {method: 'POST'})
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('logs-container').innerHTML = '<p class="p-4 text-center text-sm" style="color:var(--dim)">Aucun log recent</p>';
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Sauvegarde des parametres
document.getElementById('settings-form').addEventListener('submit', function(e) {
    e.preventDefault();

    const autoEnabled = document.getElementById('auto-enabled').checked;
    const interval = document.getElementById('interval').value;
    const statusEl = document.getElementById('settings-status');

    fetch('<?= url('/admin/indexing/settings') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            auto_enabled: autoEnabled,
            interval_minutes: parseInt(interval)
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            statusEl.textContent = 'Sauvegarde!';
            statusEl.className = 'text-sm';
            statusEl.style.color = 'var(--green)';
            statusEl.classList.remove('hidden');
            setTimeout(() => statusEl.classList.add('hidden'), 2000);
        } else {
            statusEl.textContent = 'Erreur: ' + (data.error || 'inconnue');
            statusEl.className = 'text-sm';
            statusEl.style.color = 'var(--red)';
            statusEl.classList.remove('hidden');
        }
    })
    .catch(err => {
        statusEl.textContent = 'Erreur: ' + err;
        statusEl.className = 'text-sm';
        statusEl.style.color = 'var(--red)';
        statusEl.classList.remove('hidden');
    });
});
</script>

<style>
/* Toggle "indexation automatique" — couleurs tokenisees (piste/knob/etat coche via variables). */
.idx-switch { background: var(--hover); }
.idx-switch::after { background: var(--surface); border-color: var(--border); }
#auto-enabled:checked + .idx-switch { background: var(--accent); }
#auto-enabled:focus + .idx-switch { box-shadow: 0 0 0 4px var(--accent-soft); }
</style>
