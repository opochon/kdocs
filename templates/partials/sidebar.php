<?php
/**
 * Routeur sidebar user vs admin (B0.8).
 * $sidebarMode : 'user' | 'admin' | null (auto depuis la route).
 */
$sidebarMode = $sidebarMode ?? null;
if ($sidebarMode === null) {
    $currentRoute = $_SERVER['REQUEST_URI'] ?? '/';
    $basePath = \KDocs\Core\Config::basePath();
    $sidebarMode = isAdminChromeRoute($currentRoute, $basePath) ? 'admin' : 'user';
}

if ($sidebarMode === 'admin') {
    include __DIR__ . '/sidebar_admin.php';
} else {
    include __DIR__ . '/sidebar_user.php';
}
