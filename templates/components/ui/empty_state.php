<?php
/**
 * Composant empty_state — état vide standardisé (design system Karbonic).
 * Props :
 *   title       string  titre court
 *   description string  explication / action suivante
 *   icon        string  contenu du <path> SVG (optionnel)
 */
/** @var string $title */
/** @var string $description */
/** @var string $icon */
$icon = $icon ?? 'M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4';
?>
<div class="ds-empty-state text-center py-10 px-4" role="status">
    <svg class="w-10 h-10 mx-auto mb-3" style="color:var(--dim)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="<?= htmlspecialchars($icon) ?>"></path>
    </svg>
    <p class="text-sm font-medium" style="color:var(--ink)"><?= htmlspecialchars($title) ?></p>
    <p class="text-sm mt-1" style="color:var(--dim)"><?= htmlspecialchars($description) ?></p>
</div>
