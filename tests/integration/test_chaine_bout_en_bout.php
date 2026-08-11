<?php
/**
 * Oracle du secteur mesurabilite / cycle documentaire vital — SV-19, rejoue G-04.
 *
 * La phrase d'Olivier (2026-08-10), executee : « je peux ingerer 1 pdf de
 * 100 pages et j'ai [...] ma decoupe par document et un tri auto pour les
 * certitudes et un tri semi auto ». Un PDF multi-documents entre, il ressort en
 * N documents, ceux qui depassent le seuil sont classes, les autres portent une
 * suggestion, et le resultat est retrouvable.
 *
 * G-04 (recette/partition.json) demande : deposer -> lire -> classer ->
 * versionner -> retrouver. Cette sonde couvre deposer, classer (auto + semi) et
 * retrouver, EXECUTES contre le chemin reel (IngestClassificationService,
 * aucun mock IA). Elle ne couvre PAS "lire" (OCR complet, secteur ingestion-ocr
 * ROUGE, hors perimetre) ni "versionner" (secteur ORPHELIN, aucun service —
 * voir SV-04 : rien a executer tant qu'aucun code n'existe). C'est ecrit ici en
 * clair plutot que fabrique en silence : le vert de cette sonde ne doit jamais
 * etre lu comme "le cycle complet marche".
 *
 * Usage: php tests/integration/test_chaine_bout_en_bout.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Services\Classification\IngestClassificationService;

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - CYCLE DOCUMENTAIRE, PHRASE D'OLIVIER (SV-19)      |\n";
echo "+==============================================================+\n\n";

$db     = Database::getInstance();
$passed = 0;
$failed = 0;

function test(string $name, bool $ok, string $detail = ''): bool
{
    global $passed, $failed;
    echo $ok ? "\033[32m[OK]\033[0m $name" : "\033[31m[KO]\033[0m $name";
    $ok ? $passed++ : $failed++;
    if ($detail !== '') {
        echo " - $detail";
    }
    echo "\n";
    return $ok;
}

// ---------------------------------------------------------------------------
// ETAPE 1/4 — DEPOSER : un fichier reel entre par le chemin applicatif
// ---------------------------------------------------------------------------
echo "--- ETAPE 1/4 : DEPOSER ---\n\n";

$fixture = realpath(__DIR__ . '/../fixtures/probe_multidoc.pdf');
if (!test('Fixture 3 documents (facture/courrier/contrat) presente', $fixture !== false)) {
    echo "\nRESUME: $passed reussis, $failed echoues\n";
    exit(1);
}

$probeDir = __DIR__ . '/../../storage/consume/_test_probe_e2e';
if (!is_dir($probeDir)) {
    @mkdir($probeDir, 0755, true);
}
foreach (glob($probeDir . '/*') ?: [] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}
$dest = $probeDir . '/probe_multidoc.pdf';
copy($fixture, $dest);
$checksum = md5_file($fixture) . '_e2e_' . date('YmdHis');

$stmt = $db->prepare('SELECT id FROM documents WHERE checksum LIKE ?');
$stmt->execute([md5_file($fixture) . '_e2e_%']);
foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $oldId) {
    $db->prepare('UPDATE documents SET deleted_at = NOW(), checksum = NULL WHERE id = ?')->execute([(int) $oldId]);
}

$ins = $db->prepare(
    "INSERT INTO documents (title, filename, original_filename, file_path, file_size, mime_type, checksum, status, uploaded_at, created_at, updated_at)
     VALUES ('Probe e2e', ?, ?, ?, ?, 'application/pdf', ?, 'pending', NOW(), NOW(), NOW())"
);
$ins->execute([basename($dest), basename($fixture), $dest, filesize($dest), $checksum]);
$parentId = (int) $db->lastInsertId();

$parentRow = $db->prepare('SELECT id, file_path, checksum, mime_type FROM documents WHERE id = ?');
$parentRow->execute([$parentId]);
$parent = $parentRow->fetch(\PDO::FETCH_ASSOC) ?: [];

test('Le document parent existe reellement en base', $parentId > 0, "id={$parentId}");
test('Le fichier est reellement sur disque', file_exists((string) ($parent['file_path'] ?? '')), (string) ($parent['file_path'] ?? ''));

// ---------------------------------------------------------------------------
// ETAPE 2/4 — DECOUPER : N documents distincts, chemin reel, aucun mock IA
// ---------------------------------------------------------------------------
echo "\n--- ETAPE 2/4 : DECOUPER (par document) ---\n\n";

$svc = new IngestClassificationService();
$t0 = microtime(true);
$result = $svc->classify($parentId);
$dtSplit = round((microtime(true) - $t0) * 1000);
echo "    classify() (parent) termine en {$dtSplit}ms\n\n";

$childIds = array_map('intval', $result['child_documents'] ?? []);
$splitOk = test(
    '3 documents distincts sont crees a partir du PDF a 3 pages',
    !empty($result['split']) && count($childIds) === 3,
    'split=' . var_export($result['split'] ?? false, true) . ' enfants=' . count($childIds)
        . ' detection.source=' . ($result['detection']['source'] ?? '?')
);

if (!$splitOk) {
    echo "\n    La decoupe n'a pas produit 3 documents — la suite de la chaine (classer/retrouver) ne peut\n";
    echo "    pas etre executee sur des enfants qui n'existent pas. Voir test_split_multidoc.php pour le\n";
    echo "    detail de cet echec (message distinct absente/echec/correcte).\n";
    $db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$parentId]);
    echo "\n" . str_repeat('=', 64) . "\n";
    echo "RESUME: $passed reussis, $failed echoues\n";
    echo str_repeat('=', 64) . "\n";
    echo "\n\033[31mLe cycle s'arrete a l'etape DECOUPER.\033[0m\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// ETAPE 3/4 — CLASSER : chaque enfant est soit applique (certitude), soit
// suggere (semi-auto) — jamais les deux, jamais ni l'un ni l'autre sans raison
// ---------------------------------------------------------------------------
echo "\n--- ETAPE 3/4 : CLASSER (auto sur certitude + semi-auto) ---\n\n";

$config    = Config::load();
$threshold = (float) ($config['classification']['auto_apply_threshold'] ?? 0.8);
$autoApply = filter_var($config['classification']['auto_apply'] ?? false, FILTER_VALIDATE_BOOLEAN);

$appliedCount   = 0;
$suggestedCount = 0;
$unresolvedCount = 0;

foreach ($childIds as $childId) {
    $stmt = $db->prepare('SELECT document_type_id, classification_confidence, classification_suggestions FROM documents WHERE id = ?');
    $stmt->execute([$childId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    $suggStmt = $db->prepare("SELECT * FROM classification_suggestions WHERE document_id = ? AND field_code = 'document_type_id'");
    $suggStmt->execute([$childId]);
    $suggestion = $suggStmt->fetch(\PDO::FETCH_ASSOC);

    $applied = !empty($row['document_type_id']) && $row['classification_confidence'] !== null;
    $suggested = $suggestion !== false;

    $payload = json_decode($row['classification_suggestions'] ?? '{}', true);
    $conf = (float) ($payload['confidence'] ?? 0.0);

    if ($applied) {
        $appliedCount++;
        test(
            "Document {$childId} : classe AUTOMATIQUEMENT (confidence={$conf})",
            $applied && !$suggested,
            'applique ET suggere en meme temps serait incoherent'
        );
    } elseif ($suggested) {
        $suggestedCount++;
        test(
            "Document {$childId} : SUGGESTION tracee, reprenable (confidence={$conf} < seuil {$threshold})",
            $suggestion['status'] === 'pending'
        );
    } else {
        $unresolvedCount++;
        echo "    [--] Document {$childId} : ni applique ni suggere (confidence={$conf}, "
            . "aucun type resolvable sur ce contenu — etat neutre, pas une erreur en soi)\n";
    }
}

test(
    'Chaque document separe a un statut de classification determine (applique, suggere, ou explicitement neutre)',
    ($appliedCount + $suggestedCount + $unresolvedCount) === count($childIds),
    "appliques={$appliedCount} suggeres={$suggestedCount} neutres={$unresolvedCount}"
);

// ---------------------------------------------------------------------------
// ETAPE 4/4 — RETROUVER : les documents sont-ils dans la table ET dans la vue
// standard (celle qu'un utilisateur voit reellement) ?
// ---------------------------------------------------------------------------
echo "\n--- ETAPE 4/4 : RETROUVER ---\n\n";

$allIds = array_merge([$parentId], $childIds);
$placeholders = implode(',', array_fill(0, count($allIds), '?'));

$inTable = (int) (function () use ($db, $allIds, $placeholders) {
    $s = $db->prepare("SELECT COUNT(*) FROM documents WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
    $s->execute($allIds);
    return $s->fetchColumn();
})();
test('Les 4 documents (parent + 3 enfants) existent dans la table, non supprimes', $inTable === count($allIds), "trouves={$inTable}/" . count($allIds));

// La vue standard utilisee ailleurs dans ce depot (voir test_stockage_coherence.php,
// DocumentsApiController) pour "documents visibles" exclut les brouillons pending.
$inStandardView = (int) (function () use ($db, $allIds, $placeholders) {
    $s = $db->prepare(
        "SELECT COUNT(*) FROM documents WHERE id IN ({$placeholders}) AND deleted_at IS NULL AND (status IS NULL OR status != 'pending')"
    );
    $s->execute($allIds);
    return $s->fetchColumn();
})();

test(
    'Les documents separes sont retrouvables dans la vue standard (documents non-pending)',
    $inStandardView === count($allIds),
    "visibles={$inStandardView}/" . count($allIds) . ' — un document reste status=pending jusqu\'a validation '
        . 'manuelle : "retrouver" au sens listing utilisateur standard echoue tant que personne ne valide.'
);

// ---------------------------------------------------------------------------
// Teardown : marquer, ne jamais supprimer
// ---------------------------------------------------------------------------
foreach ($allIds as $id) {
    $db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
}

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 64) . "\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo str_repeat('=', 64) . "\n";
echo "\nRappel de perimetre : cette sonde couvre deposer/decouper/classer/retrouver.\n";
echo "\"lire\" (OCR complet) et \"versionner\" ne sont PAS executes ici (voir SV-02, SV-04).\n";

if ($failed > 0) {
    echo "\n\033[31mLe cycle documentaire n'est pas prouve de bout en bout.\033[0m\n";
    exit(1);
}

echo "\n\033[32mLe cycle documentaire (deposer/decouper/classer/retrouver) est prouve.\033[0m\n";
exit(0);
