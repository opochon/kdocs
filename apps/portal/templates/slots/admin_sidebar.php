<?php
/**
 * Slot admin.sidebar.navigation — alimenté par K-Portail (portal).
 * INVISIBLE tant que PORTAL_APP_ENABLED est absent : View::pluginSlot() ne
 * rend que les apps activées. Fragment posé d'avance pour le jour où le
 * portail s'allume (voir config.php).
 */
use KDocs\Core\View;
?>
<li>
<?= View::component('ui/nav_item', [
    'href'   => url('/portal'),
    'label'  => 'Portail clients',
    'icon'   => 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9',
]) ?>
</li>
