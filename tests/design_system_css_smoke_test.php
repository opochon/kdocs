<?php

declare(strict_types=1);

/**
 * Smoke CSS — les tokens Karbonic :root doivent parser (commentaires sans fermeture prematuree).
 */
$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/public/css/design-system.css');

$failed = 0;
$passed = 0;

function assert_true(string $label, bool $ok): void
{
    global $failed, $passed;
    if ($ok) {
        echo "  ✓ {$label}\n";
        $passed++;
    } else {
        echo "  ✗ {$label}\n";
        $failed++;
    }
}

echo "Design system CSS tokens\n";
assert_true('pas de sequence --bg-*/ dans commentaire', !str_contains($css, '--bg-*/'));
assert_true('bloc html tokens clair present', (bool) preg_match('/html\s*\{[^}]*--app-bg\s*:/s', $css));
assert_true('bloc .dark present', str_contains($css, '.dark {') && str_contains($css, '--app-bg:#1f1f20'));

echo str_repeat('-', 40) . "\n";
echo "Resultat : {$passed} passes, {$failed} echoues\n";
exit($failed > 0 ? 1 : 0);
