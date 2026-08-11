<?php
/**
 * Fonctions helper globales pour K-Docs
 */

use KDocs\Core\Config;

if (!function_exists('loadEnv')) {
    /**
     * Charge un fichier .env simple (KEY=VALUE) vers $_ENV / putenv.
     */
    function loadEnv(?string $path = null): void
    {
        $path = $path ?? dirname(__DIR__) . '/.env';
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, " \t\"'");
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

loadEnv();

if (!function_exists('env')) {
    /**
     * Lit une variable d'environnement avec valeur par défaut.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        return ($value === false || $value === null || $value === '') ? $default : $value;
    }
}

if (!function_exists('url')) {
    /**
     * Génère une URL avec le base path de l'application
     * 
     * @param string $path Chemin relatif (ex: '/login' ou 'login')
     * @return string URL complète avec base path
     */
    function url(string $path = ''): string
    {
        $basePath = Config::basePath();
        $path = ltrim($path, '/');
        return $basePath . ($path ? '/' . $path : '');
    }
}

if (!function_exists('asset')) {
    /**
     * URL d'un asset statique sous public/ (CSS, JS, images).
     * Ex. asset('css/tailwind.css') → /kdocs/public/css/tailwind.css
     */
    function asset(string $path): string
    {
        $relative = ltrim($path, '/');
        $url = url('public/' . $relative);
        $file = dirname(__DIR__) . '/public/' . $relative;
        if (is_file($file)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($file);
        }

        return $url;
    }
}

if (!function_exists('isAppDebug')) {
    function isAppDebug(): bool
    {
        return filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);
    }
}

if (!function_exists('isAdminChromeRoute')) {
    /**
     * Détermine si la route courante utilise le chrome admin (sidebar admin).
     */
    function isAdminChromeRoute(?string $currentRoute = null, ?string $basePath = null): bool
    {
        $currentRoute = $currentRoute ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $basePath = $basePath ?? Config::basePath();
        $prefixes = [
            $basePath . '/admin',
            $basePath . '/time',
        ];
        foreach ($prefixes as $prefix) {
            if ($prefix !== $basePath && str_starts_with($currentRoute, $prefix)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('isQdrantUiEnabled')) {
    /**
     * Afficher l'UI Qdrant / recherche vectorielle (infra déployée et activée).
     */
    function isQdrantUiEnabled(): bool
    {
        return filter_var(Config::get('qdrant.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }
}

if (!function_exists('documentVisibilitySql')) {
    /**
     * Filtre SQL excluant les documents de test (test_*) hors mode debug.
     *
     * @param string $alias Alias table documents (ex. "d")
     */
    function documentVisibilitySql(string $alias = 'documents'): string
    {
        if (isAppDebug()) {
            return '1=1';
        }

        $t = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'documents';

        return "({$t}.title NOT LIKE 'test\\_%' AND ({$t}.original_filename IS NULL OR {$t}.original_filename NOT LIKE 'test\\_%'))";
    }
}

if (!function_exists('shellSidebarStats')) {
    /**
     * Compteurs sidebar user — source unique (Bibliothèque, À traiter).
     *
     * @return array{documents: int, pending_validation: int, tasks: int, inbox_badge: int}
     */
    function shellSidebarStats(?int $userId = null): array
    {
        static $cache = [];

        $key = $userId ?? 0;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $stats = [
            'documents' => 0,
            'pending_validation' => 0,
            'tasks' => 0,
            'inbox_badge' => 0,
        ];

        try {
            $db = \KDocs\Core\Database::getInstance();
            $docFilter = documentVisibilitySql('documents');
            $stats['documents'] = (int) $db->query(
                "SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL AND {$docFilter}"
            )->fetchColumn();
            // deleted_at IS NULL : sans cette clause le compteur additionnait la corbeille.
            // Mesure du 2026-08-11 : 385 = 195 vivants + 190 supprimes. L'ecart entre le
            // badge (385) et la page /mes-taches (195) etait exactement le nombre de
            // documents a la corbeille — on annoncait a l'utilisateur du travail sur des
            // documents qu'il avait supprimes.
            $stats['pending_validation'] = (int) $db->query(
                "SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL AND status IN ('pending', 'needs_review')"
            )->fetchColumn();

            if ($userId !== null && $userId > 0) {
                $taskService = new \KDocs\Services\TaskUnifiedService();
                $taskCounts = $taskService->getTaskCounts($userId);
                $stats['tasks'] = (int) ($taskCounts['total'] ?? 0);
            }
        } catch (\Exception $e) {
            // Setup minimal sans BDD
        }

        $stats['inbox_badge'] = max($stats['pending_validation'], $stats['tasks']);
        $cache[$key] = $stats;

        return $stats;
    }
}

if (!function_exists('documentThumbnailUrl')) {
    /** URL miniature document (fallback géré côté composant). */
    function documentThumbnailUrl(int $documentId): string
    {
        return url('/documents/' . $documentId . '/thumbnail');
    }
}

if (!function_exists('documentThumbnailPlaceholderUrl')) {
    /** SVG placeholder uniforme quand la miniature est absente. */
    function documentThumbnailPlaceholderUrl(): string
    {
        return asset('img/document-placeholder.svg');
    }
}

if (!function_exists('icon')) {
    /**
     * SVG lucide inline — jeu d'icônes unique de la famille Karbonic
     * (voir docs/DESIGN-SYSTEM-KARBONIC.md §13). Remplace Font Awesome.
     * Les SVG sont vendorés localement dans public/icons/lucide/ (aucun CDN).
     *
     * La taille suit la font-size (1em, comme Font Awesome) et la couleur suit
     * currentColor → `text-2xl`, `text-red-500`, `style="color:var(--dim)"` marchent.
     *
     * @param string $name  Nom court (alias FA : 'trash', 'plus'…) ou nom lucide direct.
     * @param array  $attrs Attributs HTML : 'class', 'style', 'title' (→ aria-label), 'width'…
     */
    function icon(string $name, array $attrs = []): string
    {
        static $cache = [];
        // Alias FA (nom court) → fichier lucide. Un nom absent ici est cherché tel quel
        // (il est déjà un nom lucide, ex. icon('users')).
        static $alias = [
            'trash' => 'trash-2', 'times' => 'x', 'info-circle' => 'info',
            'spinner' => 'loader-circle', 'robot' => 'bot', 'magic' => 'wand-sparkles',
            'edit' => 'square-pen', 'check-circle' => 'circle-check', 'times-circle' => 'circle-x',
            'layer-group' => 'layers', 'exclamation-triangle' => 'triangle-alert',
            'circle-half-stroke' => 'contrast', 'check-double' => 'check-check', 'bolt' => 'zap',
            'code-branch' => 'git-branch', 'hourglass-half' => 'hourglass', 'cog' => 'settings',
            'cubes' => 'boxes', 'sliders-h' => 'sliders-horizontal', 'comments' => 'messages-square',
            'paper-plane' => 'send', 'file-invoice' => 'file-text', 'file-pdf' => 'file-text',
            'file-alt' => 'file-text', 'list-ol' => 'list-ordered', 'sync' => 'refresh-cw',
            'shield-alt' => 'shield', 'pause-circle' => 'circle-pause', 'filter' => 'funnel',
        ];
        $file = $alias[$name] ?? $name;
        if (!array_key_exists($file, $cache)) {
            $path = dirname(__DIR__) . '/public/icons/lucide/' . $file . '.svg';
            $cache[$file] = is_file($path) ? (string) file_get_contents($path) : '';
        }
        $svg = $cache[$file];
        if ($svg === '') {
            // Icône introuvable : marqueur invisible + title (repérage en dev), jamais un trou muet.
            return '<span class="lucide-missing" title="icon manquant: ' . htmlspecialchars($name) . '" aria-hidden="true"></span>';
        }

        $class = trim('lucide ' . (string) ($attrs['class'] ?? ''));
        $style = 'width:1em;height:1em;vertical-align:-0.125em;' . (string) ($attrs['style'] ?? '');
        unset($attrs['class'], $attrs['style']);

        $out = 'class="' . htmlspecialchars($class) . '" style="' . htmlspecialchars($style) . '"';
        if (!empty($attrs['title'])) {
            $out .= ' role="img" aria-label="' . htmlspecialchars((string) $attrs['title']) . '"';
        } else {
            $out .= ' aria-hidden="true" focusable="false"';
        }
        unset($attrs['title']);
        foreach ($attrs as $k => $v) {
            $out .= ' ' . htmlspecialchars((string) $k) . '="' . htmlspecialchars((string) $v) . '"';
        }
        // Retire les width/height=24 du source lucide (on pilote la taille en 1em via style)
        // puis injecte nos attributs dans la balise <svg>.
        $svg = preg_replace('/\s(?:width|height)="24"/', '', $svg, 2);

        return preg_replace('/<svg\b/', '<svg ' . $out, (string) $svg, 1);
    }
}
