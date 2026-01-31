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

<?php if ($showEmptyPasswordWarning): ?>
<div class="bg-amber-500 text-white px-4 py-2 text-sm flex items-center justify-between">
    <div class="flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <span><strong>Sécurité :</strong> Le compte root n'a pas de mot de passe. <a href="<?= url('/admin/users') ?>" class="underline font-medium">Définissez-en un rapidement</a></span>
    </div>
</div>
<?php elseif ($showWeakPasswordWarning): ?>
<div class="bg-red-500 text-white px-4 py-2 text-sm flex items-center justify-between">
    <div class="flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <span><strong>Attention :</strong> Votre mot de passe est trop faible. <a href="<?= url('/admin/users') ?>" class="underline font-medium">Changez-le rapidement</a> pour sécuriser votre compte.</span>
    </div>
</div>
<?php endif; ?>

<header class="bg-white border-b border-gray-100">
    <div class="flex items-center justify-between px-4 py-2">
        <div>
            <h2 class="text-sm font-medium text-gray-700"><?= htmlspecialchars($pageTitle ?? 'K-Docs') ?></h2>
        </div>
        
        <div class="flex items-center gap-3 justify-end">
            <?php if ($user): ?>
            <!-- Notifications dropdown -->
            <?php include __DIR__ . '/notifications_dropdown.php'; ?>

            <!-- User menu minimaliste -->
            <div class="relative">
                <button id="user-menu-toggle" 
                        class="flex items-center gap-1.5 px-2 py-1 text-sm text-gray-600 hover:bg-gray-50 rounded transition-colors">
                    <div class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">
                        <span class="text-xs font-medium text-gray-600">
                            <?= strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? '', 0, 1)) ?>
                        </span>
                    </div>
                </button>
                <div id="user-menu" class="hidden absolute right-0 mt-1 w-40 bg-white rounded shadow-lg border border-gray-100 py-1 z-50">
                    <a href="<?= url('/admin/settings') ?>" class="block px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                        Paramètres
                    </a>
                    <a href="<?= url('/auth/logout') ?>" class="block px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                        Déconnexion
                    </a>
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
