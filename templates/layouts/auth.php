<!DOCTYPE html>
<html lang="fr">
<head>
    <?php $headFull = false; $headTitle = $title ?? 'K-Docs'; include __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="ds-shell">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <?= $content ?? '' ?>
        </div>
    </div>
</body>
</html>
