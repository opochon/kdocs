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
// DS monochrome : les couleurs de categorie de type (bleu/violet/indigo/vert
// decoratifs) sont neutralisees en chip neutre tokenise. La couleur reste
// reservee aux etats (priorite/retard ci-dessous).

// Priority badge (etats -> tokens : urgent = rouge, prioritaire = ambre)
$priorityBadge = [
    'urgent' => '<span class="ds-chip--red px-2 py-0.5 text-xs font-medium rounded-full">Urgent</span>',
    'high' => '<span class="ds-chip--amber px-2 py-0.5 text-xs font-medium rounded-full">Prioritaire</span>',
    'normal' => '',
    'low' => ''
];
?>

<div class="ds-card <?= $isOverdue ? 'ds-card--alert' : '' ?> shadow-sm hover:shadow-md transition-shadow">
    <div class="p-4">
        <div class="flex items-start gap-4">
            <!-- Type icon -->
            <div class="ds-chip--neutral flex-shrink-0 p-2 rounded-lg">
                <?= $config['icon'] ?>
            </div>

            <!-- Content -->
            <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex-1 min-w-0">
                        <!-- Title -->
                        <h3 class="text-sm font-medium truncate" style="color:var(--ink)">
                            <a href="<?= htmlspecialchars($task['link'] ?? '#') ?>">
                                <?= htmlspecialchars($task['title'] ?? 'Tâche') ?>
                            </a>
                        </h3>

                        <!-- Type badge + Priority -->
                        <div class="flex items-center gap-2 mt-1">
                            <span class="ds-chip--neutral px-2 py-0.5 text-xs font-medium rounded-full">
                                <?= $config['label'] ?>
                            </span>
                            <?= $priorityBadge[$priority] ?? '' ?>
                            <?php if ($isOverdue): ?>
                            <span class="ds-chip--red px-2 py-0.5 text-xs font-medium rounded-full">
                                En retard
                            </span>
                            <?php endif; ?>
                        </div>

                        <!-- Description -->
                        <?php if (!empty($task['description'])): ?>
                        <p class="text-sm mt-2 line-clamp-2" style="color:var(--ink-soft)">
                            <?= htmlspecialchars($task['description']) ?>
                        </p>
                        <?php endif; ?>

                        <!-- Metadata -->
                        <div class="flex flex-wrap items-center gap-3 mt-2 text-xs" style="color:var(--dim)">
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
                            <span class="<?= $isOverdue ? 'font-medium' : '' ?>"<?= $isOverdue ? ' style="color:var(--red)"' : '' ?>>
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
                                class="ds-btn-green px-3 py-1.5 text-xs font-medium rounded transition-colors">
                            Approuver
                        </button>
                        <button onclick="rejectDocument(<?= $task['document_id'] ?>)"
                                class="ds-btn-red px-3 py-1.5 text-xs font-medium rounded transition-colors">
                            Rejeter
                        </button>
                        <?php elseif ($type === 'note' && !empty($task['note_id'])): ?>
                        <button onclick="markActionComplete(<?= $task['note_id'] ?>)"
                                class="ds-btn-green px-3 py-1.5 text-xs font-medium rounded transition-colors">
                            Terminé
                        </button>
                        <?php endif; ?>

                        <a href="<?= htmlspecialchars($task['link'] ?? '#') ?>"
                           class="ds-btn-soft-neutral px-3 py-1.5 text-xs font-medium rounded transition-colors">
                            Voir
                        </a>

                        <?php if (!empty($task['document_id'])): ?>
                        <button onclick="openNoteModal(<?= $task['document_id'] ?>)"
                                class="ds-row-hover p-1.5 rounded" style="color:var(--dim)"
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
