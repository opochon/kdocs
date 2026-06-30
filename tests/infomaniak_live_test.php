<?php
/**
 * GEDv1 — Infomaniak AI Tools : test live (gate de connexion).
 *
 * Gated par .env : INFOMANIAK_AI_ENABLED + clé + product_id.
 * Skip propre (exit 0) si non configuré — ne casse pas la batterie standard.
 *
 * Usage: php tests/infomaniak_live_test.php
 *
 * Vérifie :
 *  1. Détection provider (active_provider=infomaniak, Claude désactivé)
 *  2. Health GET /1/ai (produit AI Tools)
 *  3. complete() prompt court (réponse textuelle)
 *  4. Classification réelle sur un document de la base (JSON parsé)
 */
declare(strict_types=1);

require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/autoload.php';

$passed = 0;
$failed = 0;
$skipped = false;

function ok(string $label): void
{
    global $passed;
    echo "  ✓ {$label}\n";
    $passed++;
}
function ko(string $label, string $detail = ''): void
{
    global $failed;
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    $failed++;
}

echo "=== Infomaniak AI Tools — live test ===\n";

$prov = new KDocs\Services\AIProviderService();
$status = $prov->getStatus();

if ($status['active_provider'] !== 'infomaniak') {
    echo "SKIP : active_provider=" . $status['active_provider']
        . " (Infomaniak non activé dans .env). Lancez avec INFOMANIAK_AI_ENABLED=true + clé + product_id.\n";
    exit(0); // non-configuré = skip, pas échec
}
$skipped = false;

ok("active_provider=infomaniak");

if ($status['claude']['available']) {
    ko("Claude désactivé", "claude.available=true (clé résiduelle en base/code)");
} else {
    ok("Claude désactivé (claude.available=false)");
}

echo "\n--- Health (GET /1/ai) ---\n";
$inf = new KDocs\Services\InfomaniakAIService();
$health = $inf->health();
if ($health['ok'] ?? false) {
    ok("health ok, " . count($health['products'] ?? []) . " produit(s) AI Tools");
} else {
    ko("health", (string)($health['error'] ?? 'inconnu'));
}

echo "\n--- complete() (prompt court) ---\n";
$t0 = microtime(true);
$res = $prov->complete("Réponds uniquement par 'OK' si tu fonctionnes correctement.", ['max_tokens' => 50]);
$dt = round((microtime(true) - $t0) * 1000);
if ($res === null) {
    ko("complete() réponse null");
} else {
    ok("complete() provider=" . ($res['provider'] ?? '?') . " dt={$dt}ms");
    $text = trim((string)($res['text'] ?? ''));
    if ($text === '') {
        ko("texte vide");
    } else {
        ok("texte reçu (« " . mb_substr($text, 0, 40) . " »)");
    }
}

echo "\n--- classifyDocument() (doc réel) ---\n";
try {
    $db = KDocs\Core\Database::getInstance();
    $row = $db->query("SELECT id, original_filename, LEFT(content, 800) AS excerpt FROM documents WHERE content IS NOT NULL AND content != '' ORDER BY id LIMIT 1")->fetch();
    if (!$row) {
        echo "  (aucun document avec contenu en base — étape classification ignorée)\n";
    } else {
        $t0 = microtime(true);
        $cls = $prov->classifyDocument((string)$row['excerpt'], (string)($row['original_filename'] ?? 'document'));
        $dtc = round((microtime(true) - $t0) * 1000);
        if ($cls === null) {
            ko("classifyDocument null");
        } else {
            ok("classifyDocument provider=" . ($cls['provider'] ?? '?') . " confidence=" . ($cls['confidence'] ?? '?') . " dt={$dtc}ms");
            ok("title proposé : « " . mb_substr((string)($cls['title'] ?? ''), 0, 50) . " »");
        }
    }
} catch (\Throwable $e) {
    ko("classifyDocument exception", $e->getMessage());
}

echo "\n------------------------------------------------------------\n";
echo "Résultat : {$passed} réussis, {$failed} échoués\n";
exit($failed > 0 ? 1 : 0);
