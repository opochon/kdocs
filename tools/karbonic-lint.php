<?php
/**
 * Karbonic lint — garde-fou anti-dérive (L4). Portable : copié tel quel dans
 * chaque repo Karbonic (GED : tools/, K-Time : tools/). Scanne les templates et
 * refuse ce qui casse la charte (voir DESIGN-SYSTEM-KARBONIC.md).
 *
 * Usage :  php tools/karbonic-lint.php [dir1 dir2 ...]
 *   Sans argument : scanne les dossiers de templates connus du repo courant.
 *   Sortie 0 = OK, 1 = violations trouvées (utilisable en hook/CI).
 *
 * Règles :
 *   1. CDN de style interdit (cdn.tailwindcss.com…) — chaque app charge son CSS compilé.
 *   2. Couleur en dur dans style="" (#hex / rgb()) — tout passe par var(--token).
 *   3. <table> hors composant (classe kt-table / ds-table absente) — utiliser le listing commun.
 */

declare(strict_types=1);

$roots = array_slice($argv, 1);
if (!$roots) {
    // Dossiers par défaut selon le repo (l'un ou l'autre existera).
    foreach (['templates', 'apps', 'k-time-web/templates', 'src/View'] as $d) {
        if (is_dir($d)) {
            $roots[] = $d;
        }
    }
}
if (!$roots) {
    fwrite(STDERR, "Aucun dossier de templates trouvé.\n");
    exit(2);
}

/** @var array<int, array{file:string, line:int, rule:string, snippet:string}> $violations */
$violations = [];

$cdnRe   = '~cdn\.tailwindcss\.com|unpkg\.com/tailwind~i';
// style="" contenant une couleur littérale mais PAS de var(--…)
$hardColorRe = '~style="[^"]*(#[0-9a-fA-F]{3,6}\b|rgba?\()[^"]*"~';
$tableRe = '~<table(?![^>]*\b(?:kt-table|ds-table|no-lint)\b)~i';

$scan = static function (string $file) use (&$violations, $cdnRe, $hardColorRe, $tableRe): void {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $i => $line) {
        $ln = $i + 1;
        if (preg_match($cdnRe, $line)) {
            $violations[] = ['file' => $file, 'line' => $ln, 'rule' => 'CDN-STYLE', 'snippet' => trim($line)];
        }
        if (preg_match($hardColorRe, $line, $m) && !str_contains($m[0], 'var(--')) {
            $violations[] = ['file' => $file, 'line' => $ln, 'rule' => 'HARD-COLOR', 'snippet' => trim($m[0])];
        }
        if (preg_match($tableRe, $line)) {
            $violations[] = ['file' => $file, 'line' => $ln, 'rule' => 'RAW-TABLE', 'snippet' => trim($line)];
        }
    }
};

foreach ($roots as $root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if ($f->isFile() && $f->getExtension() === 'php') {
            $scan($f->getPathname());
        }
    }
}

if (!$violations) {
    echo "Karbonic lint : OK — aucune violation.\n";
    exit(0);
}

usort($violations, static fn($a, $b) => [$a['rule'], $a['file']] <=> [$b['rule'], $b['file']]);
$byRule = [];
foreach ($violations as $v) {
    $byRule[$v['rule']] = ($byRule[$v['rule']] ?? 0) + 1;
    fwrite(STDERR, sprintf("  [%s] %s:%d  %s\n", $v['rule'], $v['file'], $v['line'], mb_strimwidth($v['snippet'], 0, 90, '…')));
}
fwrite(STDERR, "\nKarbonic lint : " . count($violations) . " violation(s) — " . json_encode($byRule) . "\n");
fwrite(STDERR, "Charte : DESIGN-SYSTEM-KARBONIC.md. Exception ponctuelle justifiée : ajouter la classe/attribut `no-lint`.\n");
exit(1);
