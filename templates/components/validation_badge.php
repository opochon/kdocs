<?php
/**
 * Composant: Badge de statut de validation
 *
 * Variables attendues:
 * - $validation_status: 'pending', 'approved', 'rejected', 'na', ou null
 * - $validated_by_username: (optionnel) nom de l'utilisateur qui a validé
 * - $validated_at: (optionnel) date de validation
 * - $size: 'sm', 'md', 'lg' (défaut: 'md')
 * - $show_details: boolean (défaut: false)
 */

$status = $validation_status ?? null;
$size = $size ?? 'md';
$show_details = $show_details ?? false;

// Classes selon la taille
$sizeClasses = [
    'sm' => 'text-xs px-2 py-0.5',
    'md' => 'text-sm px-2.5 py-1',
    'lg' => 'text-base px-3 py-1.5',
];
$sizeClass = $sizeClasses[$size] ?? $sizeClasses['md'];

// Configuration selon le statut
$statusConfig = [
    'approved' => [
        'label' => 'Validé',
        'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
        'bgClass' => 'bg-green-100',
        'textClass' => 'text-green-800',
        'borderClass' => 'border-green-200',
    ],
    'rejected' => [
        'label' => 'Non validé',
        'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>',
        'bgClass' => 'bg-red-100',
        'textClass' => 'text-red-800',
        'borderClass' => 'border-red-200',
    ],
    'pending' => [
        'label' => 'En attente',
        'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        'bgClass' => 'bg-yellow-100',
        'textClass' => 'text-yellow-800',
        'borderClass' => 'border-yellow-200',
    ],
    'na' => [
        'label' => 'N/A',
        'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>',
        'bgClass' => 'bg-gray-100',
        'textClass' => 'text-gray-600',
        'borderClass' => 'border-gray-200',
    ],
];

// Si pas de statut, afficher N/A
if (!$status) {
    $status = 'na';
}

if (isset($statusConfig[$status])):
    $config = $statusConfig[$status];
?>
<span class="inline-flex items-center gap-1 rounded-full border <?= $config['bgClass'] ?> <?= $config['textClass'] ?> <?= $config['borderClass'] ?> <?= $sizeClass ?>"
      <?php if ($show_details && !empty($validated_by_username)): ?>
      title="Par <?= htmlspecialchars($validated_by_username) ?><?= !empty($validated_at) ? ' le ' . date('d/m/Y H:i', strtotime($validated_at)) : '' ?>"
      <?php endif; ?>>
    <?= $config['icon'] ?>
    <span><?= $config['label'] ?></span>
</span>
<?php if ($show_details && !empty($validated_by_username) && $status !== 'na'): ?>
<span class="text-xs text-gray-500 ml-1">
    par <?= htmlspecialchars($validated_by_username) ?>
    <?php if (!empty($validated_at)): ?>
        le <?= date('d/m/Y', strtotime($validated_at)) ?>
    <?php endif; ?>
</span>
<?php endif; ?>
<?php endif; ?>
