<?php
/**
 * Carte design system minimal.
 *
 * @var string|null $title
 * @var string|null $description
 * @var string|null $href Lien optionnel (carte cliquable)
 * @var string $class Classes additionnelles
 */
$class = trim('bg-white border border-gray-200 rounded-lg p-4 hover:border-gray-300 transition-colors ' . ($class ?? ''));
$title = $title ?? '';
$description = $description ?? '';
?>
<?php if (!empty($href)): ?>
<a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="<?= $class ?> block">
<?php else: ?>
<div class="<?= $class ?>">
<?php endif; ?>
    <?php if ($title !== ''): ?>
    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($description !== ''): ?>
    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if (!empty($slot)): ?>
    <div class="mt-2"><?= $slot ?></div>
    <?php endif; ?>
<?php if (!empty($href)): ?>
</a>
<?php else: ?>
</div>
<?php endif; ?>
