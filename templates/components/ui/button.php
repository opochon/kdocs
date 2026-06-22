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
$class .= match ($variant) {
    'primary' => ' bg-gray-900 text-white hover:bg-gray-800',
    'ghost' => ' text-gray-600 hover:bg-gray-50',
    'danger' => ' border border-red-300 text-red-700 hover:bg-red-50',
    default => ' border border-gray-300 text-gray-700 hover:bg-gray-50',
};
$label = htmlspecialchars($label ?? '', ENT_QUOTES, 'UTF-8');
?>
<?php if (!empty($href)): ?>
<a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="<?= $class ?>"><?= $label ?></a>
<?php else: ?>
<button type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" class="<?= $class ?>"><?= $label ?></button>
<?php endif; ?>
