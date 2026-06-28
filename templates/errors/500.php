<!DOCTYPE html>
<html lang="fr">
<head>
    <?php $headFull = false; $headTitle = 'Erreur serveur - K-Docs'; include __DIR__ . '/../partials/head.php'; ?>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; }
    </style>
</head>
<body class="ds-shell min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="ds-card shadow-lg p-8 text-center">
            <!-- Icon -->
            <div class="mb-6">
                <svg class="mx-auto h-24 w-24" style="color:var(--red)" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>

            <!-- Error code -->
            <h1 class="text-6xl font-bold mb-2" style="color:var(--ink)">500</h1>

            <!-- Title -->
            <h2 class="text-xl font-semibold mb-4" style="color:var(--ink-soft)">Erreur interne du serveur</h2>

            <!-- Description -->
            <p class="mb-4" style="color:var(--dim)">
                Une erreur inattendue s'est produite. Nos équipes ont été notifiées.
            </p>

            <?php if (!empty($errorMessage) && ($showDetails ?? false)): ?>
            <!-- Error details (debug mode only) -->
            <div class="border rounded-lg p-4 mb-6 text-left" style="background:color-mix(in srgb, var(--red) 12%, transparent); border-color:color-mix(in srgb, var(--red) 40%, var(--border)); color:var(--red);">
                <p class="text-sm font-medium mb-1">Détails de l'erreur :</p>
                <p class="text-sm font-mono break-all"><?= htmlspecialchars($errorMessage) ?></p>
                <?php if (!empty($errorFile)): ?>
                <p class="text-xs mt-2">
                    <?= htmlspecialchars($errorFile) ?>:<?= $errorLine ?? '' ?>
                </p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- Generic message -->
            <p class="text-sm mb-6" style="color:var(--dim)">
                Référence : <?= date('YmdHis') ?>-<?= substr(md5(uniqid()), 0, 8) ?>
            </p>
            <?php endif; ?>

            <!-- Actions -->
            <div class="space-y-3">
                <button onclick="window.location.reload()"
                        class="btn-primary block w-full font-medium py-3 px-4 rounded-lg transition duration-150">
                    Réessayer
                </button>
                <a href="<?= \KDocs\Core\Config::basePath() ?>/dashboard"
                   class="ds-btn-soft-neutral block w-full font-medium py-3 px-4 rounded-lg transition duration-150">
                    Retour au tableau de bord
                </a>
            </div>

            <!-- Help -->
            <div class="mt-6 p-4 rounded-lg" style="background:var(--app-bg)">
                <p class="text-sm mb-2" style="color:var(--ink-soft)">Que pouvez-vous faire ?</p>
                <ul class="text-sm text-left list-disc list-inside space-y-1" style="color:var(--dim)">
                    <li>Rafraîchir la page</li>
                    <li>Vérifier votre connexion internet</li>
                    <li>Réessayer dans quelques minutes</li>
                    <li>Contacter l'administrateur si le problème persiste</li>
                </ul>
            </div>
        </div>

        <!-- Footer -->
        <p class="text-center text-sm mt-4" style="color:var(--dim)">
            K-Docs &copy; <?= date('Y') ?>
        </p>
    </div>
</body>
</html>
