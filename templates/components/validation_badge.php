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
// 'chip' = classe d'etat tokenisee (design-system.css), native clair/sombre.
$statusConfig = [
    'approved' => [
        'label' => 'Validé',
        'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
        'chip' => 'ds-chip--green',
    ],
    'rejected' => [
        'label' => 'Non validé',
        'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>',
        'chip' => 'ds-chip--red',
    ],
    'pending' => [
        'label' => 'En attente',
        'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        'chip' => 'ds-chip--amber',
    ],
    'na' => [
        'label' => 'N/A',
        'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>',
        'chip' => 'ds-chip--neutral',
    ],
];

// Si pas de statut, afficher N/A
if (!$status) {
    $status = 'na';
}

if (isset($statusConfig[$status])):
    $config = $statusConfig[$status];
?>
<span class="inline-flex items-center gap-1 rounded-full <?= $config['chip'] ?> <?= $sizeClass ?>"
      <?php if ($show_details && !empty($validated_by_username)): ?>
      title="Par <?= htmlspecialchars($validated_by_username) ?><?= !empty($validated_at) ? ' le ' . date('d/m/Y H:i', strtotime($validated_at)) : '' ?>"
      <?php endif; ?>>
    <?= $config['icon'] ?>
    <span><?= $config['label'] ?></span>
</span>
<?php if ($show_details && !empty($validated_by_username) && $status !== 'na'): ?>
<span class="text-xs ml-1" style="color:var(--dim)">
    par <?= htmlspecialchars($validated_by_username) ?>
    <?php if (!empty($validated_at)): ?>
        le <?= date('d/m/Y', strtotime($validated_at)) ?>
    <?php endif; ?>
</span>
<?php endif; ?>
<?php endif; ?>
