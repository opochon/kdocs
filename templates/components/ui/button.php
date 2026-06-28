<?php
/**
 * Bouton design system minimal.
 *
 * @var string $label
 * @var string|null $href Si défini → lien <a>, sinon <button>
 * @var string $variant primary|secondary|ghost|danger
 * @var string|null $type type button (défaut button)
 * @var string $class Classes additionnelles
 */
$variant = $variant ?? 'secondary';
$type = $type ?? 'button';
$class = trim(($class ?? '') . ' inline-flex items-center justify-center px-3 py-1.5 text-sm rounded-lg transition-colors');
// Couleurs via classes tokenisees (theme.css / design-system.css) : action
// primaire = anthracite (--primary), variantes natives clair/sombre.
$class .= match ($variant) {
    'primary' => ' btn-primary',
    'ghost' => ' btn-ghost',
    'danger' => ' ds-btn-soft-red',
    default => ' border btn-secondary',
};
$label = htmlspecialchars($label ?? '', ENT_QUOTES, 'UTF-8');
?>
<?php if (!empty($href)): ?>
<a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="<?= $class ?>"><?= $label ?></a>
<?php else: ?>
<button type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" class="<?= $class ?>"><?= $label ?></button>
<?php endif; ?>
