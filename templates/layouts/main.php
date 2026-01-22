<?php
// La fonction url() est chargée depuis app/helpers.php
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'K-Docs') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/kdocs/public/css/app.css">
    <script src="/kdocs/public/js/app.js"></script>
    <script src="/kdocs/public/js/ai-search.js"></script>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <?php include __DIR__ . '/../partials/sidebar.php'; ?>
        
        <!-- Main content -->
        <div class="flex-1 flex flex-col overflow-hidden bg-gray-50">
            <!-- Header -->
            <?php include __DIR__ . '/../partials/header.php'; ?>
            
                <!-- Barre de recherche et Chat IA (masqué par défaut) -->
                <?php // include __DIR__ . '/../partials/search_chat.php'; ?>
            
            <!-- Page content -->
            <main class="flex-1 overflow-y-auto <?= isset($fullHeight) && $fullHeight ? 'p-0' : 'p-6' ?>">
                <?php if (!isset($fullHeight) || !$fullHeight): ?>
                <div class="max-w-7xl mx-auto">
                <?php endif; ?>
                    <?= $content ?? '' ?>
                <?php if (!isset($fullHeight) || !$fullHeight): ?>
                </div>
                <?php endif; ?>
            </main>
            
            <!-- Footer -->
            <?php include __DIR__ . '/../partials/footer.php'; ?>
        </div>
    </div>
</body>
</html>
