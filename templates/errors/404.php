<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page non trouvée - K-Docs</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white rounded-lg shadow-lg p-8 text-center">
            <!-- Icon -->
            <div class="mb-6">
                <svg class="mx-auto h-24 w-24 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>

            <!-- Error code -->
            <h1 class="text-6xl font-bold text-gray-800 mb-2">404</h1>

            <!-- Title -->
            <h2 class="text-xl font-semibold text-gray-700 mb-4">Page non trouvée</h2>

            <!-- Description -->
            <p class="text-gray-500 mb-8">
                La page que vous recherchez n'existe pas ou a été déplacée.
            </p>

            <!-- Actions -->
            <div class="space-y-3">
                <a href="<?= \KDocs\Core\Config::basePath() ?>/dashboard"
                   class="block w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-lg transition duration-150">
                    Retour au tableau de bord
                </a>
                <a href="<?= \KDocs\Core\Config::basePath() ?>/documents"
                   class="block w-full bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium py-3 px-4 rounded-lg transition duration-150">
                    Voir les documents
                </a>
            </div>

            <!-- Help link -->
            <p class="mt-6 text-sm text-gray-400">
                Besoin d'aide ? <a href="<?= \KDocs\Core\Config::basePath() ?>/admin/settings" class="text-blue-500 hover:underline">Contactez l'administrateur</a>
            </p>
        </div>

        <!-- Footer -->
        <p class="text-center text-gray-400 text-sm mt-4">
            K-Docs &copy; <?= date('Y') ?>
        </p>
    </div>
</body>
</html>
