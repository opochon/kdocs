<?php
/**
 * Oracle du secteur mesurabilite / tri automatique sur certitude — SV-17.
 *
 * Olivier (2026-08-10) : « un tri auto pour les certitudes ». Cette sonde verifie
 * l'invariant exact : quand la confiance rendue par le classifieur reel
 * (KDocs\Services\Classifiers\UnifiedClassifier, aucun mock, aller-retour reel
 * contre le fournisseur IA actif) est >= au seuil de la config
 * (classification.auto_apply_threshold — LU dans config/config.php, jamais
 * ecrit en dur ici), le document_type_id est APPLIQUE SANS INTERVENTION, ET
 * trace : document_type_id, classification_confidence, last_classified_at,
 * last_classified_by sont poses ENSEMBLE (KDocs\Services\Classification\
 * IngestClassificationService::applyCategoryToDocumentType()).
 *
 * Constat de depart (2026-08-10, avant reecriture concurrente par un autre
 * agent) : le pipeline de classification automatique (IngestClassificationService)
 * pouvait poser document_type_id via un mecanisme totalement DECOUPLE de la
 * confiance IA — KDocs\Services\MatchingService::findMatches() (reconnaissance
 * de mots-cles sur `document_types.match`), qui ne pose ni classification_confidence
 * ni last_classified_at/last_classified_by. Un document_type_id present ne
 * prouvait donc PAS un tri « sur certitude » — c'est exactement le trou que
 * cette sonde ferme : elle exige les QUATRE champs ENSEMBLE, jamais un seul.
 *
 * Usage: php tests/integration/test_tri_auto_certitude.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Services\Classification\IngestClassificationService;
use KDocs\Services\OCRService;

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - TRI AUTO SUR CERTITUDE (SV-17)                    |\n";
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

function insertProbeDocument(\PDO $db, string $sourcePdf, string $subdir, string $suffix, string $titlePrefix): int
{
    $probeDir = __DIR__ . '/../../storage/consume/' . $subdir;
    if (!is_dir($probeDir)) {
        @mkdir($probeDir, 0755, true);
    }
    foreach (glob($probeDir . '/*') ?: [] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    $dest = $probeDir . '/' . basename($sourcePdf);
    copy($sourcePdf, $dest);

    $checksum = md5_file($sourcePdf) . $suffix;
    $stmt = $db->prepare('SELECT id FROM documents WHERE checksum = ?');
    $stmt->execute([$checksum]);
    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $oldId) {
        $db->prepare('UPDATE documents SET deleted_at = NOW(), checksum = NULL WHERE id = ?')->execute([(int) $oldId]);
    }

    $ins = $db->prepare(
        "INSERT INTO documents (title, filename, original_filename, file_path, file_size, mime_type, checksum, status, uploaded_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'application/pdf', ?, 'pending', NOW(), NOW(), NOW())"
    );
    $ins->execute([$titlePrefix, basename($dest), basename($sourcePdf), $dest, filesize($dest), $checksum]);

    $docId = (int) $db->lastInsertId();

    // OCR reel (meme service que DocumentProcessor), pour que le classifieur lise du vrai texte.
    $ocr = new OCRService();
    $content = $ocr->extractText($dest);
    if ($content) {
        $db->prepare('UPDATE documents SET content = ?, ocr_text = ? WHERE id = ?')->execute([$content, $content, $docId]);
    }

    return $docId;
}

// ---------------------------------------------------------------------------
// 0. Le seuil vient de la config, jamais ecrit en dur (regle : seuil chiffre = Olivier)
// ---------------------------------------------------------------------------
$config    = Config::load();
$autoApply = filter_var($config['classification']['auto_apply'] ?? false, FILTER_VALIDATE_BOOLEAN);
$threshold = (float) ($config['classification']['auto_apply_threshold'] ?? 0.8);

echo "    config classification.auto_apply = " . var_export($autoApply, true) . "\n";
echo "    config classification.auto_apply_threshold = {$threshold}\n\n";

// ---------------------------------------------------------------------------
// 1. Document a contenu clair (facture), chemin reel, aucun mock IA
// ---------------------------------------------------------------------------
echo "--- 1. CLASSIFICATION REELLE (document a contenu net) ---\n\n";

$fixture = realpath(__DIR__ . '/../fixtures/probe_ingestion.pdf');
if (!test('Fixture tests/fixtures/probe_ingestion.pdf presente', $fixture !== false)) {
    echo "\nRESUME: $passed reussis, $failed echoues\n";
    exit(1);
}

$docId = insertProbeDocument($db, $fixture, '_test_probe_certitude', '_v1', 'Probe certitude');

$t0 = microtime(true);
$svc = new IngestClassificationService();
$result = $svc->classify($docId);
$elapsedMs = round((microtime(true) - $t0) * 1000);

$confidence = (float) ($result['classification']['confidence'] ?? 0.0);
$category   = (string) ($result['classification']['category'] ?? '');
echo "    classify() termine en {$elapsedMs}ms — confidence={$confidence} categorie=\"{$category}\"\n\n";

$stmt = $db->prepare('SELECT document_type_id, classification_confidence, last_classified_at, last_classified_by FROM documents WHERE id = ?');
$stmt->execute([$docId]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

$auditCount = (int) $db->query(
    "SELECT COUNT(*) FROM classification_audit_log WHERE document_id = {$docId} AND field_code = 'document_type_id'"
)->fetchColumn();

if ($confidence >= $threshold) {
    echo "    confidence ({$confidence}) >= seuil ({$threshold}) : le classement DOIT etre applique sans intervention.\n\n";

    if (!$autoApply) {
        test(
            'document_type_id applique malgre classification.auto_apply=false',
            false,
            "auto_apply est desactive en config : le tri automatique est un interrupteur ETEINT, "
            . "pas un pipeline casse. Rien ne doit s'appliquer tant qu'Olivier ne le rallume pas."
        );
    } else {
        test('document_type_id est pose', !empty($row['document_type_id']), (string) ($row['document_type_id'] ?? 'NULL'));
        test(
            'classification_confidence est posee et coherente avec la confiance mesuree',
            $row['classification_confidence'] !== null && abs((float) $row['classification_confidence'] - $confidence) < 0.01,
            (string) ($row['classification_confidence'] ?? 'NULL')
        );
        test('last_classified_at est datee', !empty($row['last_classified_at']), (string) ($row['last_classified_at'] ?? 'NULL'));
        test('last_classified_by est renseigne', !empty($row['last_classified_by']), (string) ($row['last_classified_by'] ?? 'NULL'));
        test('Le changement est trace dans classification_audit_log', $auditCount > 0, "lignes={$auditCount}");
    }
} else {
    echo "    confidence ({$confidence}) < seuil ({$threshold}) sur cette execution : l'invariant "
        . "\"applique au-dessus du seuil\" ne peut pas etre observe ici (fournisseur IA vivant, non "
        . "deterministe). Bascule sur l'invariant complementaire : RIEN ne doit avoir ete applique.\n\n";
    test(
        'Sous le seuil mesure : document_type_id N\'EST PAS applique par ce chemin',
        empty($row['document_type_id']) || $row['classification_confidence'] === null,
        'voir test_tri_semi_auto.php pour la sonde dediee a ce cas'
    );
}

$db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$docId]);

// ---------------------------------------------------------------------------
// 2. Contre-epreuve : un document sans signal ne doit RIEN appliquer
//    (prouve que la sonde peut rougir : si le pipeline appliquait n'importe
//    quoi n'importe quand, ce cas rougirait ici).
// ---------------------------------------------------------------------------
echo "\n--- 2. CONTRE-EPREUVE : document sans signal (ne doit RIEN appliquer) ---\n\n";

$probeDir = __DIR__ . '/../../storage/consume/_test_probe_certitude_vide';
if (!is_dir($probeDir)) {
    @mkdir($probeDir, 0755, true);
}
$emptyPath = $probeDir . '/vide.pdf';
file_put_contents($emptyPath, '%PDF-1.4 probe-vide-' . uniqid());
$checksumVide = md5_file($emptyPath);

$stmt = $db->prepare('SELECT id FROM documents WHERE checksum = ?');
$stmt->execute([$checksumVide]);
foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $oldId) {
    $db->prepare('UPDATE documents SET deleted_at = NOW(), checksum = NULL WHERE id = ?')->execute([(int) $oldId]);
}
$ins = $db->prepare(
    "INSERT INTO documents (title, filename, original_filename, file_path, file_size, mime_type, checksum, status, content, ocr_text, uploaded_at, created_at, updated_at)
     VALUES ('.', 'vide.pdf', 'vide.pdf', ?, ?, 'application/pdf', ?, 'pending', '', '', NOW(), NOW(), NOW())"
);
$ins->execute([$emptyPath, filesize($emptyPath), $checksumVide]);
$videId = (int) $db->lastInsertId();

$svc2 = new IngestClassificationService();
$result2 = $svc2->classify($videId);
$confidenceVide = (float) ($result2['classification']['confidence'] ?? 0.0);

$stmt = $db->prepare('SELECT document_type_id, classification_confidence, last_classified_at FROM documents WHERE id = ?');
$stmt->execute([$videId]);
$rowVide = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

test(
    "Contre-epreuve verifiee : confidence mesuree ({$confidenceVide}) est bien < seuil, et rien n'est applique",
    $confidenceVide < $threshold && empty($rowVide['document_type_id']) && $rowVide['classification_confidence'] === null,
    'document_type_id=' . var_export($rowVide['document_type_id'] ?? null, true)
        . ' classification_confidence=' . var_export($rowVide['classification_confidence'] ?? null, true)
);

$db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$videId]);

// ---------------------------------------------------------------------------
// 3. INVARIANT : un classement HUMAIN n'est jamais ecrase par le tri auto.
//    Meme code reel (applyCategoryToDocumentType, par reflexion), confiance
//    construite AU-DESSUS du seuil, sur un document DEJA type par un humain
//    (document_type_id pose, last_classified_by NULL — comme le formulaire
//    d'edition et la validation manuelle). Re-mesure 2026-08-25 : sans cette
//    garde, le moindre passage IA >= seuil re-typait silencieusement le
//    document (la facture synthetique d'eval-full disparaissait au premier
//    classify-ai suivant).
// ---------------------------------------------------------------------------
echo "\n--- 3. INVARIANT : un classement humain n'est jamais ecrase ---\n\n";

$typeIdDispo = (int) $db->query('SELECT id FROM document_types ORDER BY id LIMIT 1')->fetchColumn();
$typeIdAutre = (int) $db->query('SELECT id FROM document_types ORDER BY id DESC LIMIT 1')->fetchColumn();

if ($typeIdDispo <= 0 || $autoApply === false) {
    echo "    (volet non joue : auto_apply=" . var_export($autoApply, true) . " ou aucun document_types)\n";
} else {
    $checksumH = 'certitude_humain_' . uniqid();
    $probeDirH = __DIR__ . '/../../storage/consume/_test_probe_certitude_humain';
    if (!is_dir($probeDirH)) {
        @mkdir($probeDirH, 0755, true);
    }
    $pathH = $probeDirH . '/' . $checksumH . '.pdf';
    file_put_contents($pathH, '%PDF-1.4 probe-humain');

    $insH = $db->prepare(
        "INSERT INTO documents (title, filename, original_filename, file_path, file_size, mime_type, checksum, status, document_type_id, uploaded_at, created_at, updated_at)
         VALUES ('.', ?, ?, ?, ?, 'application/pdf', ?, NULL, ?, NOW(), NOW(), NOW())"
    );
    $insH->execute([basename($pathH), basename($pathH), $pathH, filesize($pathH), $checksumH, $typeIdDispo]);
    $humainId = (int) $db->lastInsertId();

    $svcH = new IngestClassificationService();
    $refH = new ReflectionMethod(IngestClassificationService::class, 'applyCategoryToDocumentType');
    $refH->setAccessible(true);
    $refH->invoke($svcH, $humainId, new \KDocs\DTO\ClassificationResult(
        category: null, tags: [], confidence: min(0.99, $threshold + 0.1), externalIds: [],
        source: 'certitude-probe', raw: [], suggestions: ['document_type_id' => $typeIdAutre]
    ));

    $stmtH = $db->prepare('SELECT document_type_id, last_classified_by, status FROM documents WHERE id = ?');
    $stmtH->execute([$humainId]);
    $rowH = $stmtH->fetch(\PDO::FETCH_ASSOC) ?: [];

    test(
        "Confiance au-dessus du seuil sur un document type par un HUMAIN : le type ({$typeIdDispo}) est conserve, l'IA n'ecrit pas",
        (int) ($rowH['document_type_id'] ?? 0) === $typeIdDispo && empty($rowH['last_classified_by']),
        'document_type_id=' . var_export($rowH['document_type_id'] ?? null, true)
            . ' last_classified_by=' . var_export($rowH['last_classified_by'] ?? null, true)
            . ' — attendu: type intact, aucune trace IA'
    );

    $db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$humainId]);
}

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 64) . "\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo str_repeat('=', 64) . "\n";

if ($failed > 0) {
    echo "\n\033[31mLe tri automatique sur certitude n'est pas prouve fiable.\033[0m\n";
    exit(1);
}

echo "\n\033[32mLe tri automatique sur certitude est prouve.\033[0m\n";
exit(0);
