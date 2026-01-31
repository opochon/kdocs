<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erreur serveur - K-Docs</title>
    <link rel="stylesheet" href="/kdocs/public/css/tailwind.css">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white rounded-lg shadow-lg p-8 text-center">
            <!-- Icon -->
            <div class="mb-6">
                <svg class="mx-auto h-24 w-24 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>

            <!-- Error code -->
            <h1 class="text-6xl font-bold text-gray-800 mb-2">500</h1>

            <!-- Title -->
            <h2 class="text-xl font-semibold text-gray-700 mb-4">Erreur interne du serveur</h2>

            <!-- Description -->
            <p class="text-gray-500 mb-4">
                Une erreur inattendue s'est produite. Nos équipes ont été notifiées.
            </p>

            <?php if (!empty($errorMessage) && ($showDetails ?? false)): ?>
            <!-- Error details (debug mode only) -->
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6 text-left">
                <p class="text-sm font-medium text-red-800 mb-1">Détails de l'erreur :</p>
                <p class="text-sm text-red-700 font-mono break-all"><?= htmlspecialchars($errorMessage) ?></p>
                <?php if (!empty($errorFile)): ?>
                <p class="text-xs text-red-600 mt-2">
                    <?= htmlspecialchars($errorFile) ?>:<?= $errorLine ?? '' ?>
                </p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- Generic message -->
            <p class="text-gray-400 text-sm mb-6">
                Référence : <?= date('YmdHis') ?>-<?= substr(md5(uniqid()), 0, 8) ?>
            </p>
            <?php endif; ?>

            <!-- Actions -->
            <div class="space-y-3">
                <button onclick="window.location.reload()"
                        class="block w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-lg transition duration-150">
                    Réessayer
                </button>
                <a href="<?= \KDocs\Core\Config::basePath() ?>/dashboard"
                   class="block w-full bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium py-3 px-4 rounded-lg transition duration-150">
                    Retour au tableau de bord
                </a>
            </div>

            <!-- Help -->
            <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-600 mb-2">Que pouvez-vous faire ?</p>
                <ul class="text-sm text-gray-500 text-left list-disc list-inside space-y-1">
                    <li>Rafraîchir la page</li>
                    <li>Vérifier votre connexion internet</li>
                    <li>Réessayer dans quelques minutes</li>
                    <li>Contacter l'administrateur si le problème persiste</li>
                </ul>
            </div>
        </div>

        <!-- Footer -->
        <p class="text-center text-gray-400 text-sm mt-4">
            K-Docs &copy; <?= date('Y') ?>
        </p>
    </div>
</body>
</html>
