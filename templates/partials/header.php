<?php
$user = $user ?? null;
$currentRoute = $_SERVER['REQUEST_URI'] ?? '/';
?>

<header class="bg-white border-b border-gray-100">
    <div class="flex items-center justify-between px-4 py-2">
        <div>
            <h2 class="text-sm font-medium text-gray-700"><?= htmlspecialchars($pageTitle ?? 'K-Docs') ?></h2>
        </div>
        
        <div class="flex items-center gap-2 justify-end">
            <!-- User menu minimaliste -->
            <?php if ($user): ?>
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
