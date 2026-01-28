<?php
// La fonction url() est chargée depuis app/helpers.php
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-url" content="<?= \KDocs\Core\Config::basePath() ?>">
    <?= \KDocs\Core\CSRF::metaTag() ?>
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
            <main class="flex-1 <?= isset($fullHeight) && $fullHeight ? 'overflow-hidden p-0' : 'overflow-y-auto p-6' ?>">
                <?php 
                $currentRoute = $_SERVER['REQUEST_URI'] ?? '/';
                $basePath = \KDocs\Core\Config::basePath();
                // Détecter la page documents/index (pas /documents/show ou autres sous-routes)
                $documentsBasePath = $basePath . '/documents';
                $routeLength = strlen($currentRoute);
                $baseLength = strlen($documentsBasePath);
                $isDocumentsIndexPage = (strpos($currentRoute, $documentsBasePath) === 0) && 
                                        ($routeLength === $baseLength || 
                                         ($routeLength > $baseLength && in_array($currentRoute[$baseLength], ['?', '#'])));
                ?>
                <?php if ((!isset($fullHeight) || !$fullHeight) && !$isDocumentsIndexPage): ?>
                <div class="max-w-7xl mx-auto">
                <?php endif; ?>
                    <?= $content ?? '' ?>
                <?php if ((!isset($fullHeight) || !$fullHeight) && !$isDocumentsIndexPage): ?>
                </div>
                <?php endif; ?>
            </main>
            
            <!-- Footer -->
            <?php include __DIR__ . '/../partials/footer.php'; ?>
        </div>
    </div>
    <!-- Auto-refresh apres indexation -->
    <script>
    (function() {
        let lastIndexTime = localStorage.getItem('kdocs_last_index_time') || null;
        let isIndexingPage = window.location.pathname.includes('/admin/indexing');

        function checkIndexingStatus() {
            fetch('<?= \KDocs\Core\Config::basePath() ?>/admin/indexing/status')
            .then(r => r.json())
            .then(data => {
                if (data.success && data.status) {
                    const progress = data.status.progress || {};

                    // Si indexation vient de se terminer
                    if (progress.status === 'completed' && progress.updated_at) {
                        const newTime = String(progress.updated_at);

                        if (lastIndexTime === null) {
                            lastIndexTime = newTime;
                            localStorage.setItem('kdocs_last_index_time', newTime);
                        } else if (newTime !== lastIndexTime) {
                            lastIndexTime = newTime;
                            localStorage.setItem('kdocs_last_index_time', newTime);

                            // Ne pas recharger si on est sur la page d'indexation (elle gere elle-meme)
                            if (!isIndexingPage) {
                                // Afficher une notification et recharger
                                showIndexingNotification();
                            }
                        }
                    }
                }
            })
            .catch(() => {});
        }

        function showIndexingNotification() {
            // Creer une notification discrete
            const notif = document.createElement('div');
            notif.className = 'fixed bottom-4 right-4 bg-green-600 text-white px-4 py-3 rounded-lg shadow-lg z-50 flex items-center gap-3';
            notif.innerHTML = `
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                <span>Indexation terminee - Rafraichissement...</span>
            `;
            document.body.appendChild(notif);

            // Recharger la page apres 1.5 secondes
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        }

        // Verifier toutes les 15 secondes (pas trop frequent)
        setInterval(checkIndexingStatus, 15000);

        // Premiere verification apres 3 secondes
        setTimeout(checkIndexingStatus, 3000);
    })();
    </script>
</body>
</html>
