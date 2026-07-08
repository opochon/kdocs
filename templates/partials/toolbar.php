<?php
/**
 * Toolbar de liste — zone recherche globale + filtres (Karbonic v2, doc §12).
 * Recherche globale à gauche (GET), slot filtres transverses à droite.
 *
 * Variables attendues :
 *   $searchName        string  nom du champ GET (défaut 'q')
 *   $searchValue       string  valeur courante (défaut '')
 *   $searchPlaceholder string  placeholder (défaut 'Rechercher…')
 *   $filtersHtml       string  HTML des filtres transverses (selects…), optionnel
 *   $action            string  action du <form> (défaut '' = même URL, méthode GET)
 */
$searchName        = $searchName        ?? 'q';
$searchValue       = $searchValue       ?? '';
$searchPlaceholder = $searchPlaceholder ?? 'Rechercher…';
$filtersHtml       = $filtersHtml       ?? '';
$action            = $action            ?? '';
?>
<form class="ds-toolbar" method="get"<?= $action !== '' ? ' action="' . htmlspecialchars($action) . '"' : '' ?>>
  <div class="ds-toolbar__search">
    <svg class="ds-toolbar__search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/>
    </svg>
    <input type="search" name="<?= htmlspecialchars($searchName) ?>"
           value="<?= htmlspecialchars((string) $searchValue) ?>"
           placeholder="<?= htmlspecialchars($searchPlaceholder) ?>">
  </div>
  <?php if ($filtersHtml !== ''): ?>
  <div class="ds-toolbar__spacer"></div>
  <div class="ds-toolbar__filters"><?= $filtersHtml ?></div>
  <?php endif; ?>
</form>
