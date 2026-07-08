<?php
// Variables passées depuis le contrôleur
$stats = $stats ?? [];
$documentsByMonth = $documentsByMonth ?? [];
$documentsByType = $documentsByType ?? [];
$documentsByCorrespondent = $documentsByCorrespondent ?? [];
$amountsByMonth = $amountsByMonth ?? [];
$recentDocuments = $recentDocuments ?? [];
$pendingDocuments = $pendingDocuments ?? 0;
?>

<div class="space-y-6">
    <div class="ds-card shadow p-6">
        <h2 class="text-2xl font-bold mb-4" style="color:var(--ink)">Bienvenue, <?= htmlspecialchars($user['first_name'] ?? $user['username']) ?> !</h2>
        <p style="color:var(--ink-soft)">Vue d'ensemble de vos documents et statistiques.</p>
    </div>

    <!-- Statistiques principales -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="ds-card shadow p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-lg ds-chip--accent">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium" style="color:var(--ink-soft)">Documents totaux</p>
                    <p class="text-2xl font-bold" style="color:var(--ink)"><?= number_format($stats['total_documents'] ?? 0) ?></p>
                </div>
            </div>
        </div>

        <div class="ds-card shadow p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-lg ds-chip--green">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium" style="color:var(--ink-soft)">Documents indexés</p>
                    <p class="text-2xl font-bold" style="color:var(--ink)"><?= number_format($stats['indexed_documents'] ?? 0) ?></p>
                </div>
            </div>
        </div>

        <div class="ds-card shadow p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-lg ds-chip--amber">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium" style="color:var(--ink-soft)">En attente</p>
                    <p class="text-2xl font-bold" style="color:var(--ink)"><?= number_format($pendingDocuments) ?></p>
                </div>
            </div>
        </div>

        <div class="ds-card shadow p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-lg ds-chip--neutral">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium" style="color:var(--ink-soft)">Tâches</p>
                    <p class="text-2xl font-bold" style="color:var(--ink)"><?= number_format($stats['total_tasks'] ?? 0) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Graphiques -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Documents par mois -->
        <div class="ds-card shadow p-6">
            <h3 class="text-lg font-semibold mb-4" style="color:var(--ink)">Documents par mois</h3>
            <div style="height: 250px; position: relative;">
                <canvas id="documentsByMonthChart"></canvas>
            </div>
        </div>

        <!-- Répartition par type -->
        <div class="ds-card shadow p-6">
            <h3 class="text-lg font-semibold mb-4" style="color:var(--ink)">Répartition par type</h3>
            <div style="height: 250px; position: relative;">
                <canvas id="documentsByTypeChart"></canvas>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Répartition par correspondant -->
        <div class="ds-card shadow p-6">
            <h3 class="text-lg font-semibold mb-4" style="color:var(--ink)">Top correspondants</h3>
            <div style="height: 250px; position: relative;">
                <canvas id="documentsByCorrespondentChart"></canvas>
            </div>
        </div>

        <!-- Montants par mois -->
        <?php if (!empty($amountsByMonth)): ?>
        <div class="ds-card shadow p-6">
            <h3 class="text-lg font-semibold mb-4" style="color:var(--ink)">Montants par mois</h3>
            <div style="height: 250px; position: relative;">
                <canvas id="amountsByMonthChart"></canvas>
            </div>
        </div>
        <?php else: ?>
        <div class="ds-card shadow p-6">
            <h3 class="text-lg font-semibold mb-4" style="color:var(--ink)">Statistiques supplémentaires</h3>
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span style="color:var(--ink-soft)">Correspondants</span>
                    <span class="font-bold"><?= number_format($stats['total_correspondents'] ?? 0) ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span style="color:var(--ink-soft)">Étiquettes</span>
                    <span class="font-bold"><?= number_format($stats['total_tags'] ?? 0) ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Documents récents -->
    <div class="ds-card shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold" style="color:var(--ink)">Documents récents</h3>
            <a href="<?= url('/documents') ?>" class="text-sm">Voir tout →</a>
        </div>
        <?php if (empty($recentDocuments)): ?>
        <p class="text-center py-8" style="color:var(--dim)">Aucun document récent</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase">Document</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase">Correspondant</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentDocuments as $doc): ?>
                    <tr>
                        <td class="px-4 py-3">
                            <a href="<?= url('/documents/' . $doc['id']) ?>">
                                <?= htmlspecialchars($doc['title'] ?: $doc['original_filename'] ?: $doc['filename']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-sm" style="color:var(--ink-soft)">
                            <?= htmlspecialchars($doc['document_type_label'] ?: '-') ?>
                        </td>
                        <td class="px-4 py-3 text-sm" style="color:var(--ink-soft)">
                            <?= htmlspecialchars($doc['correspondent_name'] ?: '-') ?>
                        </td>
                        <td class="px-4 py-3 text-sm" style="color:var(--ink-soft)">
                            <?= $doc['created_at'] ? date('d/m/Y', strtotime($doc['created_at'])) : '-' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Actions rapides -->
    <div class="ds-card shadow p-6">
        <h3 class="text-lg font-semibold mb-4" style="color:var(--ink)">Actions rapides</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <a href="<?= url('/documents/upload') ?>" class="flex items-center p-4 ds-card ds-card--link">
                <div class="flex-shrink-0 w-10 h-10 mr-4 rounded-lg ds-chip--accent flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                </div>
                <div>
                    <p class="font-medium" style="color:var(--ink)">Uploader un document</p>
                    <p class="text-sm" style="color:var(--dim)">Ajouter un nouveau document au système</p>
                </div>
            </a>
            <a href="<?= url('/tasks') ?>" class="flex items-center p-4 ds-card ds-card--link">
                <div class="flex-shrink-0 w-10 h-10 mr-4 rounded-lg ds-chip--neutral flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                </div>
                <div>
                    <p class="font-medium" style="color:var(--ink)">Voir mes tâches</p>
                    <p class="text-sm" style="color:var(--dim)">Consulter les tâches qui vous sont assignées</p>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Documents par mois
const documentsByMonthData = <?= json_encode($documentsByMonth) ?>;
const documentsByMonthCtx = document.getElementById('documentsByMonthChart');
if (documentsByMonthCtx) {
    new Chart(documentsByMonthCtx, {
        type: 'line',
        data: {
            labels: documentsByMonthData.map(d => d.month),
            datasets: [{
                label: 'Documents',
                data: documentsByMonthData.map(d => d.count),
                borderColor: 'rgb(59, 130, 246)',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            }
        }
    });
}

// Répartition par type
const documentsByTypeData = <?= json_encode($documentsByType) ?>;
const documentsByTypeCtx = document.getElementById('documentsByTypeChart');
if (documentsByTypeCtx) {
    new Chart(documentsByTypeCtx, {
        type: 'doughnut',
        data: {
            labels: documentsByTypeData.map(d => d.type || 'Non défini'),
            datasets: [{
                data: documentsByTypeData.map(d => d.count),
                backgroundColor: [
                    'rgb(59, 130, 246)',
                    'rgb(16, 185, 129)',
                    'rgb(245, 158, 11)',
                    'rgb(239, 68, 68)',
                    'rgb(139, 92, 246)',
                    'rgb(236, 72, 153)',
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });
}

// Répartition par correspondant
const documentsByCorrespondentData = <?= json_encode($documentsByCorrespondent) ?>;
const documentsByCorrespondentCtx = document.getElementById('documentsByCorrespondentChart');
if (documentsByCorrespondentCtx) {
    new Chart(documentsByCorrespondentCtx, {
        type: 'bar',
        data: {
            labels: documentsByCorrespondentData.map(d => d.correspondent),
            datasets: [{
                label: 'Documents',
                data: documentsByCorrespondentData.map(d => d.count),
                backgroundColor: 'rgb(16, 185, 129)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}

// Montants par mois
const amountsByMonthData = <?= json_encode($amountsByMonth) ?>;
const amountsByMonthCtx = document.getElementById('amountsByMonthChart');
if (amountsByMonthCtx && amountsByMonthData.length > 0) {
    // Grouper par mois et devise
    const grouped = {};
    amountsByMonthData.forEach(d => {
        if (!grouped[d.month]) grouped[d.month] = {};
        grouped[d.month][d.currency] = parseFloat(d.total);
    });
    
    const currencies = [...new Set(amountsByMonthData.map(d => d.currency))];
    const months = Object.keys(grouped).sort();
    
    new Chart(amountsByMonthCtx, {
        type: 'bar',
        data: {
            labels: months,
            datasets: currencies.map((currency, idx) => ({
                label: currency,
                data: months.map(m => grouped[m][currency] || 0),
                backgroundColor: ['rgb(59, 130, 246)', 'rgb(16, 185, 129)', 'rgb(245, 158, 11)'][idx % 3]
            }))
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}
</script>
