<?php
/**
 * Helper: Champ CSRF pour formulaires
 * Usage: <?php include __DIR__ . '/../partials/csrf_field.php'; ?>
 */
use KDocs\Core\CSRF;
echo CSRF::field();
