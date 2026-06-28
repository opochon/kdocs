<?php
require __DIR__ . '/sidebar_nav_helpers.php';

$userId = isset($user['id']) ? (int) $user['id'] : null;
$stats = shellSidebarStats($userId);
?>

<aside class="ds-sidebar">
    <div class="ds-sidebar__brand">
        <h1 class="ds-sidebar__brand-title">K-Docs</h1>
    </div>

    <nav class="ds-sidebar__nav">
        <ul>
            <li>
                <a href="<?= url('/documents') ?>" class="ds-nav-item <?= sidebarIsActive('/documents', $currentRoute, $basePath) ? 'is-active' : '' ?>">
                    <span class="ds-nav-item__main">
                        <svg class="ds-nav-item__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span class="ds-nav-item__label">Bibliothèque</span>
                    </span>
                    <?php if ($stats['documents'] > 0): ?>
                    <span class="ds-nav-count"><?= $stats['documents'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="<?= url('/search') ?>" class="ds-nav-item <?= sidebarIsActive('/search', $currentRoute, $basePath) || sidebarIsActive('/chat', $currentRoute, $basePath) ? 'is-active' : '' ?>">
                    <span class="ds-nav-item__main">
                        <svg class="ds-nav-item__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <span class="ds-nav-item__label">Recherche</span>
                    </span>
                </a>
            </li>
            <li>
                <a href="<?= url('/mes-taches') ?>" class="ds-nav-item <?= sidebarIsActive('/mes-taches', $currentRoute, $basePath) ? 'is-active' : '' ?>">
                    <span class="ds-nav-item__main">
                        <svg class="ds-nav-item__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                        </svg>
                        <span class="ds-nav-item__label">À traiter</span>
                    </span>
                    <?php if ($stats['inbox_badge'] > 0): ?>
                    <span class="ds-nav-badge"><?= $stats['inbox_badge'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="<?= url('/documents/upload') ?>" class="ds-nav-item <?= sidebarIsActive('/documents/upload', $currentRoute, $basePath) ? 'is-active' : '' ?>">
                    <span class="ds-nav-item__main">
                        <svg class="ds-nav-item__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                        </svg>
                        <span class="ds-nav-item__label">Importer</span>
                    </span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="ds-sidebar__foot">
        <a href="<?= url('/admin') ?>" class="ds-nav-item <?= sidebarIsActive('/admin', $currentRoute, $basePath) ? 'is-active' : '' ?>">
            <span class="ds-nav-item__main">
                <svg class="ds-nav-item__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <span class="ds-nav-item__label">Administration</span>
            </span>
        </a>
    </div>

    <?php if ($user): ?>
    <div class="ds-userbox">
        <p class="ds-userbox-name"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></p>
        <p class="ds-userbox-mail"><?= htmlspecialchars($user['email'] ?? '') ?></p>
    </div>
    <?php endif; ?>
</aside>
