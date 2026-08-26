<?php
/**
 * Slot admin.sidebar.navigation — alimenté par l'app K-Time (timetrack).
 * Rendu par KDocs\Core\View::pluginSlot() UNIQUEMENT quand l'app est activée
 * (PluginRegistry::isEnabled) : éteindre l'app retire l'entrée de la sidebar
 * sans toucher au gabarit du shell.
 *
 * Contexte disponible : $slot, $app_name, $user, $currentRoute, $basePath.
 * Convention : un fragment de zone navigation fournit des <li> complets.
 */
use KDocs\Core\View;
?>
<li>
<?= View::component('ui/nav_item', [
    'href'   => url('/time'),
    'label'  => 'K-Time',
    'icon'   => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    'active' => isset($currentRoute) && str_contains((string) $currentRoute, '/time'),
]) ?>
</li>
