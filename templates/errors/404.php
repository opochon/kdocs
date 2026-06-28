<!DOCTYPE html>
<html lang="fr">
<head>
    <?php $headFull = false; $headTitle = 'Page non trouvée - K-Docs'; include __DIR__ . '/../partials/head.php'; ?>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; }
    </style>
</head>
<body class="ds-shell min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="ds-card shadow-lg p-8 text-center">
            <!-- Icon -->
            <div class="mb-6">
                <svg class="mx-auto h-24 w-24" style="color:var(--dim)" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>

            <!-- Error code -->
            <h1 class="text-6xl font-bold mb-2" style="color:var(--ink)">404</h1>

            <!-- Title -->
            <h2 class="text-xl font-semibold mb-4" style="color:var(--ink-soft)">Page non trouvée</h2>

            <!-- Description -->
            <p class="mb-8" style="color:var(--dim)">
                La page que vous recherchez n'existe pas ou a été déplacée.
            </p>

            <!-- Actions -->
            <div class="space-y-3">
                <a href="<?= \KDocs\Core\Config::basePath() ?>/dashboard"
                   class="btn-primary block w-full font-medium py-3 px-4 rounded-lg transition duration-150">
                    Retour au tableau de bord
                </a>
                <a href="<?= \KDocs\Core\Config::basePath() ?>/documents"
                   class="ds-btn-soft-neutral block w-full font-medium py-3 px-4 rounded-lg transition duration-150">
                    Voir les documents
                </a>
            </div>

            <!-- Help link -->
            <p class="mt-6 text-sm" style="color:var(--dim)">
                Besoin d'aide ? <a href="<?= \KDocs\Core\Config::basePath() ?>/admin/settings" class="hover:underline" style="color:var(--accent)">Contactez l'administrateur</a>
            </p>
        </div>

        <!-- Footer -->
        <p class="text-center text-sm mt-4" style="color:var(--dim)">
            K-Docs &copy; <?= date('Y') ?>
        </p>
    </div>
</body>
</html>
