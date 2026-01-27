<?php
/**
 * Composant: Carte de tâche unifiée
 *
 * Variables attendues:
 * - $task: array avec id, type, title, description, status, priority, deadline, link, action_link, metadata
 */

$task = $task ?? [];
$type = $task['type'] ?? 'unknown';
$priority = $task['priority'] ?? 'normal';
$deadline = $task['deadline'] ?? null;
$isOverdue = $deadline && strtotime($deadline) < time();

// Configuration des types
$typeConfig = [
    'validation' => [
        'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        'color' => 'blue',
        'label' => 'Validation'
    ],
    'consume' => [
        'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>',
        'color' => 'purple',
        'label' => 'Classification'
    ],
    'workflow' => [
        'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>',
        'color' => 'indigo',
        'label' => 'Workflow'
    ],
    'note' => [
        'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>',
        'color' => 'green',
        'label' => 'Note'
    ]
];

$config = $typeConfig[$type] ?? $typeConfig['validation'];
$colorClasses = [
    'blue' => ['bg' => 'bg-blue-50', 'border' => 'border-blue-200', 'text' => 'text-blue-700', 'icon' => 'text-blue-500'],
    'purple' => ['bg' => 'bg-purple-50', 'border' => 'border-purple-200', 'text' => 'text-purple-700', 'icon' => 'text-purple-500'],
    'indigo' => ['bg' => 'bg-indigo-50', 'border' => 'border-indigo-200', 'text' => 'text-indigo-700', 'icon' => 'text-indigo-500'],
    'green' => ['bg' => 'bg-green-50', 'border' => 'border-green-200', 'text' => 'text-green-700', 'icon' => 'text-green-500'],
];
$colors = $colorClasses[$config['color']] ?? $colorClasses['blue'];

// Priority badge
$priorityBadge = [
    'urgent' => '<span class="px-2 py-0.5 text-xs font-medium bg-red-100 text-red-800 rounded-full">Urgent</span>',
    'high' => '<span class="px-2 py-0.5 text-xs font-medium bg-orange-100 text-orange-800 rounded-full">Prioritaire</span>',
    'normal' => '',
    'low' => ''
];
?>

<div class="bg-white border <?= $isOverdue ? 'border-red-300' : 'border-gray-200' ?> rounded-lg shadow-sm hover:shadow-md transition-shadow">
    <div class="p-4">
        <div class="flex items-start gap-4">
            <!-- Type icon -->
            <div class="flex-shrink-0 p-2 rounded-lg <?= $colors['bg'] ?> <?= $colors['icon'] ?>">
                <?= $config['icon'] ?>
            </div>

            <!-- Content -->
            <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex-1 min-w-0">
                        <!-- Title -->
                        <h3 class="text-sm font-medium text-gray-900 truncate">
                            <a href="<?= htmlspecialchars($task['link'] ?? '#') ?>" class="hover:text-blue-600">
                                <?= htmlspecialchars($task['title'] ?? 'Tâche') ?>
                            </a>
                        </h3>

                        <!-- Type badge + Priority -->
                        <div class="flex items-center gap-2 mt-1">
                            <span class="px-2 py-0.5 text-xs font-medium <?= $colors['bg'] ?> <?= $colors['text'] ?> rounded-full">
                                <?= $config['label'] ?>
                            </span>
                            <?= $priorityBadge[$priority] ?? '' ?>
                            <?php if ($isOverdue): ?>
                            <span class="px-2 py-0.5 text-xs font-medium bg-red-100 text-red-800 rounded-full">
                                En retard
                            </span>
                            <?php endif; ?>
                        </div>

                        <!-- Description -->
                        <?php if (!empty($task['description'])): ?>
                        <p class="text-sm text-gray-600 mt-2 line-clamp-2">
                            <?= htmlspecialchars($task['description']) ?>
                        </p>
                        <?php endif; ?>

                        <!-- Metadata -->
                        <div class="flex flex-wrap items-center gap-3 mt-2 text-xs text-gray-500">
                            <?php if (!empty($task['metadata']['document_type'])): ?>
                            <span><?= htmlspecialchars($task['metadata']['document_type']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($task['metadata']['correspondent'])): ?>
                            <span><?= htmlspecialchars($task['metadata']['correspondent']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($task['metadata']['amount'])): ?>
                            <span class="font-medium">
                                <?= number_format($task['metadata']['amount'], 2, '.', "'") ?>
                                <?= $task['metadata']['currency'] ?? 'CHF' ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($task['metadata']['from_user'])): ?>
                            <span>De: <?= htmlspecialchars($task['metadata']['from_user']) ?></span>
                            <?php endif; ?>
                            <?php if ($deadline): ?>
                            <span class="<?= $isOverdue ? 'text-red-600 font-medium' : '' ?>">
                                Echéance: <?= date('d/m/Y', strtotime($deadline)) ?>
                            </span>
                            <?php endif; ?>
                            <span>Créé: <?= date('d/m/Y', strtotime($task['created_at'])) ?></span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex-shrink-0 flex items-center gap-2">
                        <?php if ($type === 'validation' && !empty($task['document_id'])): ?>
                        <button onclick="approveDocument(<?= $task['document_id'] ?>)"
                                class="px-3 py-1.5 text-xs font-medium bg-green-600 text-white rounded hover:bg-green-700 transition-colors">
                            Approuver
                        </button>
                        <button onclick="rejectDocument(<?= $task['document_id'] ?>)"
                                class="px-3 py-1.5 text-xs font-medium bg-red-600 text-white rounded hover:bg-red-700 transition-colors">
                            Rejeter
                        </button>
                        <?php elseif ($type === 'note' && !empty($task['note_id'])): ?>
                        <button onclick="markActionComplete(<?= $task['note_id'] ?>)"
                                class="px-3 py-1.5 text-xs font-medium bg-green-600 text-white rounded hover:bg-green-700 transition-colors">
                            Terminé
                        </button>
                        <?php endif; ?>

                        <a href="<?= htmlspecialchars($task['link'] ?? '#') ?>"
                           class="px-3 py-1.5 text-xs font-medium bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition-colors">
                            Voir
                        </a>

                        <?php if (!empty($task['document_id'])): ?>
                        <button onclick="openNoteModal(<?= $task['document_id'] ?>)"
                                class="p-1.5 text-gray-400 hover:text-gray-600 rounded hover:bg-gray-100"
                                title="Envoyer une note">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                            </svg>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
