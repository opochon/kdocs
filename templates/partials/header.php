<?php
$user = $user ?? null;
$currentRoute = $_SERVER['REQUEST_URI'] ?? '/';
?>

<header class="bg-white border-b border-gray-200">
    <div class="flex items-center justify-between px-6 py-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($pageTitle ?? 'K-Docs') ?></h2>
            <?php if (isset($subtitle) && $subtitle): ?>
            <p class="text-sm text-gray-500 mt-0.5"><?= htmlspecialchars($subtitle) ?></p>
            <?php endif; ?>
        </div>
        
        <div class="flex items-center gap-3">
            <!-- Search (si sur page documents) -->
            <?php if (strpos($currentRoute, '/documents') !== false && !strpos($currentRoute, '/edit') && !strpos($currentRoute, '/upload')): ?>
            <div class="relative hidden md:block">
                <input type="text" 
                       id="header-search" 
                       placeholder="Rechercher..." 
                       class="w-64 pl-10 pr-4 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       onkeypress="if(event.key==='Enter') { window.location.href='<?= url('/documents') ?>?search=' + encodeURIComponent(this.value); }">
                <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>
            <?php endif; ?>
            
            <!-- Dark mode toggle -->
            <button id="dark-mode-toggle" 
                    class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-md transition-colors" 
                    title="Mode sombre">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                </svg>
            </button>
            
            <!-- User menu -->
            <?php if ($user): ?>
            <div class="relative">
                <button id="user-menu-toggle" 
                        class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-md transition-colors">
                    <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center">
                        <span class="text-xs font-medium text-blue-700">
                            <?= strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? '', 0, 1)) ?>
                        </span>
                    </div>
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
                <div id="user-menu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg border border-gray-200 py-1 z-50">
                    <a href="<?= url('/admin/settings') ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Paramètres
                    </a>
                    <a href="<?= url('/auth/logout') ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
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
