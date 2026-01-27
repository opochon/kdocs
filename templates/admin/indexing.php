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
            <h1 class="text-2xl font-semibold text-gray-900">Indexation</h1>
            <p class="text-sm text-gray-500 mt-1">Index les documents de storage/documents pour une recherche optimale</p>
        </div>

        <div class="flex gap-3">
            <button onclick="startIndexing()" id="btn-index" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed" <?= $isRunning ? 'disabled' : '' ?>>
                <svg class="w-4 h-4 <?= $isRunning ? 'animate-spin' : '' ?>" id="btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                <span id="btn-text"><?= $isRunning ? 'Indexation en cours...' : 'Indexer maintenant' ?></span>
            </button>

            <button onclick="stopIndexing()" id="btn-stop" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 flex items-center gap-2 <?= $isRunning ? '' : 'hidden' ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
                Arreter
            </button>
        </div>
    </div>

    <!-- Barre de progression -->
    <div id="progress-container" class="mb-6 <?= $isRunning ? '' : 'hidden' ?>">
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <div class="flex justify-between items-center mb-2">
                <span class="text-sm font-medium text-gray-700">Progression</span>
                <span class="text-sm text-gray-500" id="progress-percent"><?= ($progress['percent'] ?? 0) ?>%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3">
                <div class="bg-blue-600 h-3 rounded-full transition-all duration-300" id="progress-bar" style="width: <?= ($progress['percent'] ?? 0) ?>%"></div>
            </div>
            <div class="mt-2 flex justify-between text-xs text-gray-500">
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
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <p class="text-sm text-gray-500">Documents indexes</p>
            <p class="text-2xl font-semibold text-gray-900" id="stat-documents"><?= number_format($status['stats']['total_documents'] ?? 0) ?></p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <p class="text-sm text-gray-500">Dossiers</p>
            <p class="text-2xl font-semibold text-blue-600" id="stat-folders"><?= number_format($status['stats']['total_folders'] ?? 0) ?></p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <p class="text-sm text-gray-500">Statut</p>
            <p class="text-lg font-medium <?= $isRunning ? 'text-orange-600' : 'text-green-600' ?>" id="stat-status">
                <?= $isRunning ? 'Indexation en cours...' : 'Pret' ?>
            </p>
        </div>
    </div>

    <!-- Parametres d'indexation automatique -->
    <div class="bg-white rounded-lg border border-gray-200 mb-6">
        <div class="px-4 py-3 border-b border-gray-200">
            <h2 class="font-medium text-gray-900">Parametres d'indexation automatique</h2>
        </div>
        <div class="p-4">
            <form id="settings-form" class="flex flex-wrap items-end gap-4">
                <div class="flex items-center gap-3">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" id="auto-enabled" class="sr-only peer" <?= ($settings['auto_enabled'] ?? false) ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                    </label>
                    <span class="text-sm text-gray-700">Indexation automatique</span>
                </div>

                <div class="flex items-center gap-2">
                    <label for="interval" class="text-sm text-gray-700">Intervalle:</label>
                    <select id="interval" class="rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                        <?php
                        $intervals = [5 => '5 minutes', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 heure', 120 => '2 heures', 360 => '6 heures', 720 => '12 heures', 1440 => '24 heures'];
                        foreach ($intervals as $value => $label):
                        ?>
                        <option value="<?= $value ?>" <?= ($settings['interval_minutes'] ?? 60) == $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm">
                    Sauvegarder
                </button>

                <span id="settings-status" class="text-sm text-green-600 hidden">Sauvegarde!</span>
            </form>

            <?php if (!empty($settings['last_run'])): ?>
            <p class="text-xs text-gray-500 mt-3">
                Derniere execution: <?= date('d/m/Y H:i:s', $settings['last_run']) ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Info -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <p class="font-medium text-blue-900">Indexation des documents</p>
                <p class="text-sm text-blue-700 mt-1">
                    L'indexation parcourt le dossier <code class="bg-blue-100 px-1 rounded">storage/documents</code> et met a jour la base de donnees
                    pour permettre une recherche rapide et efficace. Le processus s'execute en arriere-plan.
                </p>
            </div>
        </div>
    </div>

    <!-- Logs recents -->
    <div class="bg-white rounded-lg border border-gray-200">
        <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
            <h2 class="font-medium text-gray-900">
                Logs recents
                <span id="logs-updating" class="text-xs text-gray-400 ml-2 hidden">(mise a jour...)</span>
            </h2>
            <div class="flex gap-2 items-center">
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" id="auto-refresh" checked class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    Auto-refresh
                </label>
                <button onclick="refreshLogs()" class="text-sm text-blue-600 hover:text-blue-800">
                    Rafraichir
                </button>
                <button onclick="clearLogs()" class="text-sm text-red-600 hover:text-red-800">
                    Effacer
                </button>
            </div>
        </div>
        <div class="max-h-80 overflow-y-auto" id="logs-container">
            <?php if (empty($logs)): ?>
                <p class="p-4 text-gray-500 text-center text-sm">Aucun log recent</p>
            <?php else: ?>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100" id="logs-body">
                        <?php foreach ($logs as $log): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 text-gray-400 whitespace-nowrap text-xs"><?= htmlspecialchars($log['timestamp']) ?></td>
                            <td class="px-2 py-2">
                                <?php
                                $levelColors = [
                                    'INFO' => 'bg-blue-100 text-blue-800',
                                    'WARNING' => 'bg-yellow-100 text-yellow-800',
                                    'ERROR' => 'bg-red-100 text-red-800',
                                ];
                                $color = $levelColors[$log['level']] ?? 'bg-gray-100 text-gray-800';
                                ?>
                                <span class="px-2 py-0.5 text-xs rounded <?= $color ?>"><?= $log['level'] ?></span>
                            </td>
                            <td class="px-4 py-2 text-gray-700 text-xs"><?= htmlspecialchars($log['message']) ?></td>
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
        statusEl.className = 'text-lg font-medium text-orange-600';
    } else {
        btnText.textContent = 'Indexer maintenant';
        btnIcon.classList.remove('animate-spin');
        statusEl.textContent = 'Pret';
        statusEl.className = 'text-lg font-medium text-green-600';
    }
}

function showResult(success, message) {
    const resultDiv = document.getElementById('result-message');
    const resultBox = document.getElementById('result-box');
    const resultText = document.getElementById('result-text');

    resultDiv.classList.remove('hidden');

    if (success) {
        resultBox.className = 'rounded-lg p-4 bg-green-50 border border-green-200';
        resultText.className = 'text-green-800';
    } else {
        resultBox.className = 'rounded-lg p-4 bg-red-50 border border-red-200';
        resultText.className = 'text-red-800';
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
                container.innerHTML = '<p class="p-4 text-gray-500 text-center text-sm">Aucun log recent</p>';
                return;
            }

            container.innerHTML = `<table class="w-full text-sm"><tbody class="divide-y divide-gray-100" id="logs-body"></tbody></table>`;
            const logsBody = document.getElementById('logs-body');

            logsBody.innerHTML = data.logs.map(log => {
                const colors = {
                    'INFO': 'bg-blue-100 text-blue-800',
                    'WARNING': 'bg-yellow-100 text-yellow-800',
                    'ERROR': 'bg-red-100 text-red-800'
                };
                const color = colors[log.level] || 'bg-gray-100 text-gray-800';

                return `<tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 text-gray-400 whitespace-nowrap text-xs">${escapeHtml(log.timestamp)}</td>
                    <td class="px-2 py-2"><span class="px-2 py-0.5 text-xs rounded ${color}">${log.level}</span></td>
                    <td class="px-4 py-2 text-gray-700 text-xs">${escapeHtml(log.message)}</td>
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
            document.getElementById('logs-container').innerHTML = '<p class="p-4 text-gray-500 text-center text-sm">Aucun log recent</p>';
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
            statusEl.className = 'text-sm text-green-600';
            statusEl.classList.remove('hidden');
            setTimeout(() => statusEl.classList.add('hidden'), 2000);
        } else {
            statusEl.textContent = 'Erreur: ' + (data.error || 'inconnue');
            statusEl.className = 'text-sm text-red-600';
            statusEl.classList.remove('hidden');
        }
    })
    .catch(err => {
        statusEl.textContent = 'Erreur: ' + err;
        statusEl.className = 'text-sm text-red-600';
        statusEl.classList.remove('hidden');
    });
});
</script>
