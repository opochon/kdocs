<?php
/**
 * <head> partagé — SOURCE DE VÉRITÉ UNIQUE du chargement CSS/JS + thème.
 *
 * Évite la dérive entre layouts (cause du bug : /mes-taches, 404/500 chargeaient
 * tailwind seul, sans design-system.css ni bascule de thème). Tout layout ou page
 * autonome (`<html>` propre) DOIT inclure ce partial dans son <head>.
 *
 * Variables optionnelles :
 *   $headTitle : titre de page (défaut $pageTitle ?? $title ?? 'K-Docs')
 *   $headFull  : true  = stack applicative complète (Font Awesome, theme.css, app.css,
 *                        app.js, ai-search.js, meta base-url + CSRF) — pages du shell ;
 *                false = minimal (login, erreurs).
 * design-system.css + init no-FOUC + theme.js sont chargés DANS TOUS LES CAS.
 */
$headFull  = $headFull  ?? true;
$headTitle = $headTitle ?? ($pageTitle ?? $title ?? 'K-Docs');
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php if ($headFull): ?>
<meta name="base-url" content="<?= \KDocs\Core\Config::basePath() ?>">
<?= \KDocs\Core\CSRF::metaTag() ?>
<?php endif; ?>
<title><?= htmlspecialchars($headTitle) ?></title>
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
<!-- Theme no-FOUC : applique .dark sur <html> avant le rendu CSS -->
<script>
(function(){try{var t=localStorage.getItem('kdocs-theme')||'system';var d=t==='dark'||(t==='system'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.classList.toggle('dark',d);document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
</script>
<link rel="stylesheet" href="<?= asset('css/tailwind.css') ?>">
<?php if ($headFull): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= asset('css/theme.css') ?>">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<?php endif; ?>
<!-- Design system Karbonic : toujours chargé EN DERNIER (tokens + overrides gagnent la cascade) -->
<link rel="stylesheet" href="<?= asset('css/design-system.css') ?>">
<?php if ($headFull): ?>
<script src="<?= asset('js/app.js') ?>"></script>
<script src="<?= asset('js/ai-search.js') ?>"></script>
<?php endif; ?>
<script src="<?= asset('js/theme.js') ?>" defer></script>
