<?php
/**
 * Helpers partagés sidebar user / admin.
 */
if (!function_exists('sidebarIsActive')) {
    function sidebarIsActive(string $route, string $currentRoute, string $basePath): bool
    {
        $fullRoute = $basePath . $route;
        if ($route === '/admin') {
            return $currentRoute === $fullRoute
                || $currentRoute === $fullRoute . '/'
                || (str_starts_with($currentRoute, $fullRoute . '/') && $currentRoute !== $fullRoute);
        }
        return str_starts_with($currentRoute, $fullRoute)
            || ($route === '/' && ($currentRoute === $basePath . '/' || $currentRoute === $basePath));
    }
}

$user = $user ?? null;
$currentRoute = $_SERVER['REQUEST_URI'] ?? '/';
$basePath = \KDocs\Core\Config::basePath();
