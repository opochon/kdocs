<?php
/**
 * Miniature document avec placeholder uniforme au chargement ou en erreur.
 *
 * @var int $documentId
 * @var string|null $alt
 * @var string $class Classes CSS img (défaut object-cover w-full h-full)
 */
$documentId = (int) ($documentId ?? 0);
$alt = htmlspecialchars($alt ?? 'Document', ENT_QUOTES, 'UTF-8');
$imgClass = $class ?? 'w-full h-full object-cover';
$thumbUrl = documentThumbnailUrl($documentId);
$placeholderUrl = documentThumbnailPlaceholderUrl();
?>
<img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8') ?>"
     alt="<?= $alt ?>"
     class="<?= htmlspecialchars($imgClass, ENT_QUOTES, 'UTF-8') ?>"
     loading="lazy"
     onerror="this.onerror=null;this.src='<?= htmlspecialchars($placeholderUrl, ENT_QUOTES, 'UTF-8') ?>';">
