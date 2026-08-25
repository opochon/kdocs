<?php
/**
 * Oracle S1 / DF-06 — coherence des compteurs « a classer ».
 *
 * Constat d'Olivier (2026-08-11, DF-06) : « compteurs 195/380 contradictoires ».
 * Le fix 9925fb5 a corrige helpers.php et ConsumeFolderService mais PAS la copie
 * de la meme requete dans templates/partials/sidebar_admin.php. Constat du
 * 2026-08-25 (Olivier : « finit les x documents a classer x2 pour je ne sais
 * quelle raison ») : en plus de la corbeille additionnee, des LIGNES DUPLIQUEES
 * pour un meme fichier physique (meme checksum) gonflent la file — 34 documents
 * vivants pending partagaient leur checksum avec un autre vivant a la mesure.
 *
 * Cinq controles, tous par EFFET sur le chemin reel (jamais une reecriture du
 * SQL du produit) :
 *  1. Badge sidebar admin (rendu REEL du partial) == nombre reel de documents
 *     vivants a classer (la corbeille ne compte pas).
 *  2. INVARIANT BASE : aucun document vivant « a classer » ne duplique un autre
 *     vivant par checksum — un fichier physique = une ligne en file.
 *  3. IDEMPOTENCE des suggestions : deux passages du VRAI code de decision
 *     (applyCategoryToDocumentType, par reflexion) ne produisent qu'UNE ligne
 *     pending dans classification_suggestions — le pipeline passe deux fois
 *     (fallback synchrone + worker), la suggestion ne doit pas s'empiler.
 *  4. La bibliotheque « Toutes » (rendu REEL de DocumentsController::index,
 *     racine) montre un document classe mais PAS un document en file — les
 *     pieces a classer vivent dans la file /admin/consume, pas dans la
 *     bibliotheque. Contre-epreuve : le document classe EST present.
 *  5. Aucun document n'est compte deux fois dans le total de taches
 *     (validation ET consume a la fois).
 *
 * Usage: php tests/integration/test_compteurs_coherence.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Controllers\DocumentsController;
use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\DTO\ClassificationResult;
use KDocs\Services\Classification\IngestClassificationService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - COHERENCE DES COMPTEURS (S1 / DF-06)              |\n";
echo "+==============================================================+\n\n";

$db     = Database::getInstance();
$passed = 0;
$failed = 0;

function test(string $name, bool $ok, string $detail = ''): bool
{
    global $passed, $failed;
    echo $ok ? "✓ $name" : "✗ $name";
    $ok ? $passed++ : $failed++;
    if ($detail !== '') {
        echo " - $detail";
    }
    echo "\n";
    return $ok;
}

function fakeRequest(string $method, string $uri, ?array $user): \Psr\Http\Message\ServerRequestInterface
{
    $req = (new ServerRequestFactory())->createServerRequest($method, $uri);
    return $user === null ? $req : $req->withAttribute('user', $user);
}

$adminUser = ['id' => 1, 'role' => 'admin', 'is_admin' => 1];

// Nettoyage des residus d'une execution precedente (marquer, jamais supprimer).
$db->exec("UPDATE documents SET deleted_at = COALESCE(deleted_at, NOW()), checksum = NULL
           WHERE title LIKE 'COMPTEURS-COHERENCE %' AND deleted_at IS NULL");

// ---------------------------------------------------------------------------
// 1. BADGE SIDEBAR ADMIN — rendu REEL du partial, pas la requete recopiee.
// ---------------------------------------------------------------------------
echo "--- 1. BADGE SIDEBAR ADMIN (rendu reel du partial) ---\n\n";

$baseCount = (int) $db->query(
    "SELECT COUNT(*) FROM documents WHERE status IN ('pending', 'needs_review') AND deleted_at IS NULL"
)->fetchColumn();
$trashCount = (int) $db->query(
    "SELECT COUNT(*) FROM documents WHERE status IN ('pending', 'needs_review') AND deleted_at IS NOT NULL"
)->fetchColumn();

$user = ['id' => 1, 'role' => 'admin', 'is_admin' => 1, 'first_name' => 'Probe', 'last_name' => 'Compteurs', 'email' => 'probe@kdocs.local'];
$_SERVER['REQUEST_URI'] = Config::basePath() . '/admin';
ob_start();
include __DIR__ . '/../../templates/partials/sidebar_admin.php';
$sidebarHtml = (string) ob_get_clean();

$badgeCount = null;
if (preg_match('/Fichiers à valider.{0,400}?ds-nav-badge--alert">\s*(\d+)\s*</s', $sidebarHtml, $m)) {
    $badgeCount = (int) $m[1];
} elseif ($baseCount === 0) {
    // Le badge n'est rendu QUE si > 0 (voir sidebar_admin.php) — absence avec base=0 est coherent.
    $badgeCount = 0;
}

echo "    base(vivants)={$baseCount} corbeille={$trashCount} badge=" . ($badgeCount ?? 'non rendu') . "\n\n";

test(
    'Le badge sidebar admin dit le meme nombre que la base (la corbeille ne compte pas)',
    $badgeCount === $baseCount,
    'badge=' . ($badgeCount ?? 'null') . " base={$baseCount}" . ($trashCount > 0 && $badgeCount === $baseCount + $trashCount ? ' (le badge additionnait exactement la corbeille)' : '')
);
test(
    'Le badge ne rapporte pas les documents supprimes comme travail a faire',
    $badgeCount === null || $badgeCount <= $baseCount,
    "badge={$badgeCount} base={$baseCount} corbeille={$trashCount}"
);

// ---------------------------------------------------------------------------
// 2. INVARIANT BASE — un fichier physique (checksum) = une ligne en file.
// ---------------------------------------------------------------------------
echo "\n--- 2. INVARIANT : pas de doublon de fichier physique en file ---\n\n";

$dupGroups = (int) $db->query(
    "SELECT COUNT(*) FROM (
        SELECT checksum FROM documents
        WHERE deleted_at IS NULL AND checksum IS NOT NULL
          AND status IN ('pending', 'needs_review')
        GROUP BY checksum HAVING COUNT(*) > 1
    ) x"
)->fetchColumn();

$dupExamples = $db->query(
    "SELECT d1.id, d2.id AS id_double, d1.title
     FROM documents d1
     JOIN documents d2 ON d1.checksum = d2.checksum AND d1.id < d2.id
     WHERE d1.deleted_at IS NULL AND d2.deleted_at IS NULL AND d1.checksum IS NOT NULL
       AND d1.status IN ('pending', 'needs_review')
     LIMIT 3"
)->fetchAll(\PDO::FETCH_ASSOC);

test(
    'INVARIANT BASE : aucun document vivant « a classer » ne duplique un autre vivant (meme checksum)',
    $dupGroups === 0,
    $dupGroups === 0
        ? 'aucun doublon'
        : "{$dupGroups} groupe(s) de checksum duplique — ex: " . json_encode($dupExamples, JSON_UNESCAPED_UNICODE)
);

// ---------------------------------------------------------------------------
// 3. IDEMPOTENCE DES SUGGESTIONS — le VRAI code de decision, appele deux fois.
// ---------------------------------------------------------------------------
echo "\n--- 3. IDEMPOTENCE DES SUGGESTIONS (code reel x2) ---\n\n";

$config    = Config::load();
$threshold = (float) ($config['classification']['auto_apply_threshold'] ?? 0.8);
$typeId    = (int) $db->query('SELECT id FROM document_types ORDER BY id LIMIT 1')->fetchColumn();

if ($typeId <= 0) {
    test('Un document_types existe pour jouer la frontiere', false);
} else {
    $checksum = 'compteurs_' . uniqid();
    $probeDir = __DIR__ . '/../../storage/consume/_test_probe_compteurs';
    if (!is_dir($probeDir)) {
        @mkdir($probeDir, 0755, true);
    }
    $path = $probeDir . '/' . $checksum . '.pdf';
    file_put_contents($path, '%PDF-1.4 probe-compteurs');

    $ins = $db->prepare(
        "INSERT INTO documents (title, filename, original_filename, file_path, file_size, mime_type, checksum, status, uploaded_at, created_at, updated_at)
         VALUES ('COMPTEURS-COHERENCE suggestion', ?, ?, ?, ?, 'application/pdf', ?, 'pending', NOW(), NOW(), NOW())"
    );
    $ins->execute([basename($path), basename($path), $path, filesize($path), $checksum]);
    $idempId = (int) $db->lastInsertId();

    $svc = new IngestClassificationService();
    $ref = new \ReflectionMethod(IngestClassificationService::class, 'applyCategoryToDocumentType');
    $ref->setAccessible(true);

    $lowResult = new ClassificationResult(
        category: null, tags: [], confidence: max(0.0, $threshold - 0.1), externalIds: [],
        source: 'compteurs-probe', raw: [], suggestions: ['document_type_id' => $typeId]
    );

    // Le pipeline reel passe deux fois sur un meme document (fallback synchrone
    // de queue() + worker vidant les files) : on reproduit exactement cela.
    $ref->invoke($svc, $idempId, $lowResult);
    $ref->invoke($svc, $idempId, $lowResult);

    $sugg = $db->prepare(
        "SELECT COUNT(*) FROM classification_suggestions
         WHERE document_id = ? AND field_code = 'document_type_id' AND status = 'pending'"
    );
    $sugg->execute([$idempId]);
    $pendingRows = (int) $sugg->fetchColumn();

    test(
        "Deux passages du code reel = UNE seule suggestion pending (confidence sous seuil {$threshold})",
        $pendingRows === 1,
        "lignes_pending={$pendingRows}"
    );

    $db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$idempId]);
}

// ---------------------------------------------------------------------------
// 4. BIBLIOTHEQUE « TOUTES » — rendu REEL de DocumentsController::index.
// ---------------------------------------------------------------------------
echo "\n--- 4. BIBLIOTHEQUE RACINE (DocumentsController::index, rendu reel) ---\n\n";

$mkProbe = function (string $marker, ?string $status) use ($db): int {
    $checksum = 'compteurs_' . $marker . '_' . uniqid();
    $probeDir = __DIR__ . '/../../storage/consume/_test_probe_compteurs';
    if (!is_dir($probeDir)) {
        @mkdir($probeDir, 0755, true);
    }
    $path = $probeDir . '/' . $checksum . '.pdf';
    file_put_contents($path, '%PDF-1.4 probe-' . $marker);
    $ins = $db->prepare(
        "INSERT INTO documents (title, filename, original_filename, file_path, file_size, mime_type, checksum, status, relative_path, uploaded_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'application/pdf', ?, ?, NULL, NOW(), NOW(), NOW())"
    );
    $ins->execute(['COMPTEURS-COHERENCE ' . $marker, basename($path), basename($path), $path, filesize($path), $checksum, $status]);
    return (int) $db->lastInsertId();
};

$biblioId = $mkProbe('biblio', null);        // document classe : doit etre visible
$fileId   = $mkProbe('file', 'pending');     // document en file : ne doit PAS l'etre

$ctrl = new DocumentsController();
$req = fakeRequest('GET', '/documents?folder=' . urlencode(md5('/')), $adminUser);
$resp = $ctrl->index($req, new SlimResponse());
$html = (string) $resp->getBody();

$biblioVisible = str_contains($html, 'data-doc-id="' . $biblioId . '"');
$fileVisible   = str_contains($html, 'data-doc-id="' . $fileId . '"');

test(
    'CONTRE-EPREUVE : le document classe (statut vide) est rendu dans la bibliotheque racine',
    $biblioVisible,
    "id={$biblioId}"
);
test(
    'Le document « a classer » (pending) n\'apparaît PAS dans la bibliotheque racine',
    !$fileVisible,
    "id={$fileId}" . ($fileVisible ? ' — rendu a tort : les pieces a classer appartiennent a la file /admin/consume' : '')
);

$db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id IN (?, ?)')->execute([$biblioId, $fileId]);

// ---------------------------------------------------------------------------
// 5. TOTAL DE TACHES — aucun document compte deux fois (service reel).
// ---------------------------------------------------------------------------
echo "\n--- 5. TOTAL DE TACHES (TaskUnifiedService::getTaskCounts, appel reel) ---\n\n";

$taskCounts = (new \KDocs\Services\TaskUnifiedService())->getTaskCounts(1);

$overlap = (int) $db->query(
    "SELECT COUNT(*) FROM documents
     WHERE deleted_at IS NULL
       AND status IN ('pending', 'needs_review')
       AND requires_approval = 1
       AND validation_status = 'pending'"
)->fetchColumn();

$sommeParties = (int) $taskCounts['validation'] + (int) $taskCounts['consume']
    + (int) $taskCounts['workflow'] + (int) $taskCounts['notes'];
$totalAttendu = $sommeParties - $overlap;

test(
    'Le total de taches ne compte aucun document deux fois (validation ∩ consume deduit)',
    (int) $taskCounts['total'] === $totalAttendu,
    "total={$taskCounts['total']} attendu={$totalAttendu} (parties={$sommeParties}, chevauchement={$overlap})"
);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 64) . "\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo str_repeat('=', 64) . "\n";

if ($failed > 0) {
    echo "\n\033[31mLes compteurs ne sont pas coherents.\033[0m\n";
    exit(1);
}

echo "\n\033[32mLes compteurs disent tous la meme chose.\033[0m\n";
exit(0);
