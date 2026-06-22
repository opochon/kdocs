<?php
/**
 * Badge design system minimal.
 *
 * @var string $label
 * @var string $variant primary|neutral|warning|danger (défaut neutral)
 */
$variant = $variant ?? 'neutral';
$classes = match ($variant) {
    'primary' => 'bg-blue-100 text-blue-800',
    'warning' => 'bg-amber-100 text-amber-800',
    'danger' => 'bg-red-100 text-red-800',
    default => 'bg-gray-100 text-gray-700',
};
?>
<span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full <?= $classes ?>">
    <?= htmlspecialchars($label ?? '', ENT_QUOTES, 'UTF-8') ?>
</span>
