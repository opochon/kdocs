<?php
$user = $user ?? null;
$currentRoute = $_SERVER['REQUEST_URI'] ?? '/';

// Vérifier si root a un mot de passe vide (warning sécurité)
$showEmptyPasswordWarning = false;
$showWeakPasswordWarning = false;

if ($user && ($user['username'] === 'root' || ($user['is_admin'] ?? false))) {
    try {
        $db = \KDocs\Core\Database::getInstance();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE username = 'root' LIMIT 1");
        $stmt->execute();
        $rootUser = $stmt->fetch();
        if ($rootUser && empty($rootUser['password_hash'])) {
            $showEmptyPasswordWarning = true;
        }
    } catch (\Exception $e) {}
}

// Vérifier si l'utilisateur actuel a un mot de passe faible
if ($user && !empty($_COOKIE['kdocs_weak_password'])) {
    $showWeakPasswordWarning = true;
}
?>

<?php if ($showEmptyPasswordWarning && isAppDebug()): ?>
<div class="bg-amber-500 text-white px-4 py-2 text-sm flex items-center justify-between">
    <div class="flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <span><strong>Sécurité :</strong> Le compte root n'a pas de mot de passe. <a href="<?= url('/admin/users') ?>" class="underline font-medium">Définissez-en un rapidement</a></span>
    </div>
</div>
<?php elseif ($showWeakPasswordWarning && isAppDebug()): ?>
<div class="bg-red-500 text-white px-4 py-2 text-sm flex items-center justify-between">
    <div class="flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <span><strong>Attention :</strong> Votre mot de passe est trop faible. <a href="<?= url('/admin/users') ?>" class="underline font-medium">Changez-le rapidement</a> pour sécuriser votre compte.</span>
    </div>
</div>
<?php endif; ?>

<header class="ds-header">
    <div class="ds-header__bar">
        <div>
            <h2 class="ds-header__title"><?= htmlspecialchars($pageTitle ?? 'K-Docs') ?></h2>
        </div>

        <div class="ds-header__actions">
            <!-- Bascule de thème clair / sombre / système (cf. theme.js) -->
            <button type="button" data-theme-toggle onclick="kdocsCycleTheme()" class="ds-iconbtn" title="Thème">
                <span data-theme-icon><?= icon('circle-half-stroke') ?></span>
            </button>
            <?php if ($user): ?>
            <!-- Notifications dropdown -->
            <?php include __DIR__ . '/notifications_dropdown.php'; ?>

            <!-- User menu minimaliste -->
            <div class="relative">
                <button id="user-menu-toggle" type="button" class="ds-iconbtn" style="width:auto;padding:0 3px;" aria-label="Menu utilisateur">
                    <span class="ds-avatar">
                        <?= strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? '', 0, 1)) ?>
                    </span>
                </button>
                <div id="user-menu" class="ds-menu hidden absolute right-0 mt-1 w-40 z-50">
                    <a href="<?= url('/admin/settings') ?>">Paramètres</a>
                    <?php /* Route reelle : index.php:164 declare /logout, pas /auth/logout.
                             Le menu pointait vers /auth/logout -> 404 sur tous les ecrans en
                             session. Trouve le 2026-08-11 par la spec persona-dead-links. */ ?>
                    <a href="<?= url('/logout') ?>">Déconnexion</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</header>

<script>
// Toggle user menu
document.getElementById('user-menu-toggle')?.addEventListener('click', function(e) {
    e.stopPropagation();
    const menu = document.getElementById('user-menu');
    menu.classList.toggle('hidden');
});

// Close menu on outside click
document.addEventListener('click', function() {
    document.getElementById('user-menu')?.classList.add('hidden');
});
</script>
