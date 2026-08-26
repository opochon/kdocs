<?php
/**
 * Composant nav_item — item de navigation sidebar (design system Karbonic).
 * Props :
 *   href     string  URL cible (déjà préfixée par url())
 *   label    string  libellé
 *   icon     string  contenu du <path> SVG (stroke, 24x24)
 *   active   bool    état actif
 *   badge    ?string badge d'alerte (ds-nav-badge--alert)
 *   count    ?int    compteur discret (ds-nav-count)
 */
/** @var string $href */
/** @var string $label */
/** @var string $icon */
/** @var bool $active */
/** @var string|null $badge */
/** @var int|null $count */
$active = $active ?? false;
$badge = $badge ?? null;
$count = $count ?? null;
?>
<a href="<?= htmlspecialchars($href) ?>" class="ds-nav-item <?= $active ? 'is-active' : '' ?>">
    <span class="ds-nav-item__main">
        <svg class="ds-nav-item__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= htmlspecialchars($icon) ?>"></path>
        </svg>
        <span class="ds-nav-item__label"><?= htmlspecialchars($label) ?></span>
    </span>
    <?php if ($badge !== null && $badge !== ''): ?>
    <span class="ds-nav-badge ds-nav-badge--alert"><?= htmlspecialchars((string) $badge) ?></span>
    <?php elseif ($count !== null && $count > 0): ?>
    <span class="ds-nav-count"><?= (int) $count ?></span>
    <?php endif; ?>
</a>
