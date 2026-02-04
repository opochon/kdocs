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
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-2xl font-bold text-gray-800 mb-4">Bienvenue, <?= htmlspecialchars($user['first_name'] ?? $user['username']) ?> !</h2>
        <p class="text-gray-600">Vue d'ensemble de vos documents et statistiques.</p>
    </div>

    <!-- Statistiques principales -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 bg-blue-100 rounded-lg">
                    <span class="text-2xl">📄</span>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Documents totaux</p>
                    <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['total_documents'] ?? 0) ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 bg-green-100 rounded-lg">
                    <span class="text-2xl">✅</span>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Documents indexés</p>
                    <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['indexed_documents'] ?? 0) ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 bg-yellow-100 rounded-lg">
                    <span class="text-2xl">⏳</span>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">En attente</p>
                    <p class="text-2xl font-bold text-gray-900"><?= number_format($pendingDocuments) ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 bg-purple-100 rounded-lg">
                    <span class="text-2xl">📋</span>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Tâches</p>
                    <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['total_tasks'] ?? 0) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Graphiques -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Documents par mois -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Documents par mois</h3>
            <div style="height: 250px; position: relative;">
                <canvas id="documentsByMonthChart"></canvas>
            </div>
        </div>

        <!-- Répartition par type -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Répartition par type</h3>
            <div style="height: 250px; position: relative;">
                <canvas id="documentsByTypeChart"></canvas>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Répartition par correspondant -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Top correspondants</h3>
            <div style="height: 250px; position: relative;">
                <canvas id="documentsByCorrespondentChart"></canvas>
            </div>
        </div>

        <!-- Montants par mois -->
        <?php if (!empty($amountsByMonth)): ?>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Montants par mois</h3>
            <div style="height: 250px; position: relative;">
                <canvas id="amountsByMonthChart"></canvas>
            </div>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Statistiques supplémentaires</h3>
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-gray-600">Correspondants</span>
                    <span class="font-bold"><?= number_format($stats['total_correspondents'] ?? 0) ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-gray-600">Étiquettes</span>
                    <span class="font-bold"><?= number_format($stats['total_tags'] ?? 0) ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Documents récents -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Documents récents</h3>
            <a href="<?= url('/documents') ?>" class="text-blue-600 hover:text-blue-800 text-sm">Voir tout →</a>
        </div>
        <?php if (empty($recentDocuments)): ?>
        <p class="text-gray-500 text-center py-8">Aucun document récent</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Document</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Correspondant</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($recentDocuments as $doc): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="<?= url('/documents/' . $doc['id']) ?>" class="text-blue-600 hover:text-blue-800">
                                <?= htmlspecialchars($doc['title'] ?: $doc['original_filename'] ?: $doc['filename']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <?= htmlspecialchars($doc['document_type_label'] ?: '-') ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <?= htmlspecialchars($doc['correspondent_name'] ?: '-') ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
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
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Actions rapides</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <a href="<?= url('/documents/upload') ?>" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                <span class="text-2xl mr-4">📤</span>
                <div>
                    <p class="font-medium text-gray-900">Uploader un document</p>
                    <p class="text-sm text-gray-500">Ajouter un nouveau document au système</p>
                </div>
            </a>
            <a href="<?= url('/tasks') ?>" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                <span class="text-2xl mr-4">📋</span>
                <div>
                    <p class="font-medium text-gray-900">Voir mes tâches</p>
                    <p class="text-sm text-gray-500">Consulter les tâches qui vous sont assignées</p>
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
