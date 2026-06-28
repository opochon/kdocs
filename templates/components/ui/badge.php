<?php
/**
 * Badge design system minimal.
 *
 * @var string $label
 * @var string $variant primary|neutral|warning|danger (défaut neutral)
 */
$variant = $variant ?? 'neutral';
// Chips d'etat tokenisees (design-system.css) : fond derive du token, natif clair/sombre.
$classes = match ($variant) {
    'primary' => 'ds-chip--accent',
    'warning' => 'ds-chip--amber',
    'danger' => 'ds-chip--red',
    default => 'ds-chip--neutral',
};
?>
<span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full <?= $classes ?>">
    <?= htmlspecialchars($label ?? '', ENT_QUOTES, 'UTF-8') ?>
</span>
