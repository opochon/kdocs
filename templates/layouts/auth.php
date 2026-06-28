<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'K-Docs') ?></title>
    <link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
    <!-- Theme no-FOUC : applique .dark sur <html> avant le rendu CSS -->
    <script>
    (function(){try{var t=localStorage.getItem('kdocs-theme')||'system';var d=t==='dark'||(t==='system'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.classList.toggle('dark',d);document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
    </script>
    <link rel="stylesheet" href="<?= asset('css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/design-system.css') ?>">
    <script src="<?= asset('js/theme.js') ?>" defer></script>
</head>
<body class="ds-shell">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <?= $content ?? '' ?>
        </div>
    </div>
</body>
</html>
