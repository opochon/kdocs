<?php
/**
 * Oracle du secteur mesurabilite / tri semi-automatique — SV-18.
 *
 * Olivier (2026-08-10) : « un tri semi auto ». Sous le seuil de confiance
 * (classification.auto_apply_threshold, LU dans la config, jamais ecrit en dur),
 * rien ne doit etre impose au document (document_type_id reste intact) MAIS une
 * suggestion doit exister et etre reprenable par un humain : une ligne dans la
 * table `classification_suggestions` (KDocs\Models\ClassificationSuggestion),
 * pas seulement le JSON `documents.classification_suggestions` que personne ne
 * relit (voir constat ci-dessous).
 *
 * Deux volets, chemin reel pour les deux (KDocs\Services\Classification\
 * IngestClassificationService::classify() / ::applyCategoryToDocumentType()) :
 *
 *  1. VIVANT — un document ambigu passe reellement par le classifieur (aucun
 *     mock). Le resultat depend du fournisseur IA reel : la sonde mesure la
 *     confiance obtenue et adapte son verdict a ce qui a ete reellement mesure
 *     (jamais un verdict fabrique a l'avance).
 *
 *  2. FRONTIERE — pour ne pas dependre de la chance d'obtenir une confiance
 *     precise du fournisseur IA a chaque execution, la meme methode privee
 *     applyCategoryToDocumentType() (le CODE REEL, pas une reecriture) est
 *     appelee par reflexion avec un KDocs\DTO\ClassificationResult CONSTRUIT
 *     directement (type resolvable, confiance choisie). Ce n'est PAS un mock du
 *     fournisseur IA — aucune classe IA n'est touchee — c'est un test de la
 *     frontiere de decision, sur donnees DB reelles, verifiable a la demande
 *     (voir CONTRE-EPREUVE 2) : confiance haute -> applique, confiance basse ->
 *     suggestion tracee, jamais les deux a la fois.
 *
 * Usage: php tests/integration/test_tri_semi_auto.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\DTO\ClassificationResult;
use KDocs\Services\Classification\IngestClassificationService;

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - TRI SEMI-AUTOMATIQUE (SV-18)                      |\n";
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

function insertBareProbe(\PDO $db, string $checksum, string $content = ''): int
{
    $stmt = $db->prepare('SELECT id FROM documents WHERE checksum = ?');
    $stmt->execute([$checksum]);
    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $oldId) {
        $db->prepare('UPDATE documents SET deleted_at = NOW(), checksum = NULL WHERE id = ?')->execute([(int) $oldId]);
    }
    $probeDir = __DIR__ . '/../../storage/consume/_test_probe_semiauto';
    if (!is_dir($probeDir)) {
        @mkdir($probeDir, 0755, true);
    }
    $path = $probeDir . '/' . $checksum . '.pdf';
    file_put_contents($path, '%PDF-1.4 probe-semiauto');

    $ins = $db->prepare(
        "INSERT INTO documents (title, filename, original_filename, file_path, file_size, mime_type, checksum, status, content, ocr_text, uploaded_at, created_at, updated_at)
         VALUES ('.', ?, ?, ?, ?, 'application/pdf', ?, 'pending', ?, ?, NOW(), NOW(), NOW())"
    );
    $ins->execute([basename($path), basename($path), $path, filesize($path), $checksum, $content, $content]);
    return (int) $db->lastInsertId();
}

$config    = Config::load();
$autoApply = filter_var($config['classification']['auto_apply'] ?? false, FILTER_VALIDATE_BOOLEAN);
$threshold = (float) ($config['classification']['auto_apply_threshold'] ?? 0.8);
echo "    config classification.auto_apply_threshold = {$threshold}\n\n";

// ---------------------------------------------------------------------------
// 1. VIVANT — document ambigu, chemin reel, aucun mock
// ---------------------------------------------------------------------------
echo "--- 1. DOCUMENT AMBIGU (chemin reel, confiance mesuree en direct) ---\n\n";

$content = "Bonjour,\n\nSuite a notre echange, veuillez trouver ci-joint les elements demandes. "
    . "Merci de revenir vers nous rapidement.\n\nCordialement.\n";
$checksum = 'semiauto_' . md5($content) . '_' . date('YmdHis');
$docId = insertBareProbe($db, $checksum, $content);

$t0 = microtime(true);
$svc = new IngestClassificationService();
$result = $svc->classify($docId);
$elapsedMs = round((microtime(true) - $t0) * 1000);
$confidence = (float) ($result['classification']['confidence'] ?? 0.0);
$category   = (string) ($result['classification']['category'] ?? '');
echo "    classify() termine en {$elapsedMs}ms — confidence={$confidence} categorie=\"{$category}\"\n\n";

$stmt = $db->prepare('SELECT document_type_id, classification_confidence FROM documents WHERE id = ?');
$stmt->execute([$docId]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
$nothingApplied = empty($row['document_type_id']) && $row['classification_confidence'] === null;

$suggRow = $db->prepare("SELECT * FROM classification_suggestions WHERE document_id = ? AND field_code = 'document_type_id'");
$suggRow->execute([$docId]);
$suggestion = $suggRow->fetch(\PDO::FETCH_ASSOC);

if ($confidence >= $threshold) {
    echo "    confidence ({$confidence}) >= seuil sur cette execution : le cas 'semi-auto' n'est pas observe ici "
        . "(voir test_tri_auto_certitude.php). On verifie seulement la coherence : si applique, alors correctement.\n\n";
    test(
        'Coherence : au-dessus du seuil, rien de casse (document_type_id et classification_confidence poses ensemble ou pas du tout)',
        (!empty($row['document_type_id'])) === ($row['classification_confidence'] !== null)
    );
} elseif (!empty($suggestion)) {
    echo "    confidence ({$confidence}) < seuil : un type etait resolvable, verifions la suggestion tracee.\n\n";
    test('Rien n\'est impose au document (document_type_id reste vide)', $nothingApplied, json_encode($row));
    test(
        'Une suggestion pending existe dans classification_suggestions, reprenable par un humain',
        $suggestion['status'] === 'pending' && abs((float) $suggestion['confidence'] - $confidence) < 0.01,
        json_encode($suggestion)
    );
} else {
    echo "    confidence ({$confidence}) < seuil ET aucun type n'a pu etre resolu par le classifieur sur ce "
        . "contenu ambigu : AUCUNE suggestion tracee (etat distinct — pas un defaut de la sonde, le classifieur "
        . "n'a rien a suggerer). Verifie ci-dessous avec le volet FRONTIERE que le mecanisme fonctionne "
        . "reellement des qu'un type est resolvable.\n\n";
    test('Rien n\'est impose au document (document_type_id reste vide)', $nothingApplied, json_encode($row));
    test(
        'Coherence attendue : pas de suggestion tracee quand aucun type n\'est resolvable',
        $suggestion === false
    );
}

$db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$docId]);

// ---------------------------------------------------------------------------
// 2. FRONTIERE — meme code reel (applyCategoryToDocumentType), confiance choisie
// ---------------------------------------------------------------------------
echo "\n--- 2. FRONTIERE DE DECISION (code reel, confiance construite, sur donnees DB reelles) ---\n\n";

function callApplyCategory(\PDO $db, IngestClassificationService $svc, int $documentId, ClassificationResult $classification): void
{
    $ref = new ReflectionMethod(IngestClassificationService::class, 'applyCategoryToDocumentType');
    $ref->setAccessible(true);
    $ref->invoke($svc, $documentId, $classification);
}

// Type reel existant, pour que resolveDocumentTypeId() le retrouve reellement en base.
$typeId = (int) $db->query('SELECT id FROM document_types ORDER BY id LIMIT 1')->fetchColumn();
if ($typeId <= 0) {
    test('Au moins un document_types existe pour la frontiere de decision', false);
} else {
    $svcRef = new IngestClassificationService();

    // 2a. Confiance BASSE (threshold - 0.1) : ne doit RIEN appliquer, mais tracer une suggestion.
    $lowId = insertBareProbe($db, 'frontiere_basse_' . uniqid(), '');
    $lowConfidence = max(0.0, $threshold - 0.1);
    $lowResult = new ClassificationResult(
        category: null,
        tags: [],
        confidence: $lowConfidence,
        externalIds: [],
        source: 'frontiere-test',
        raw: [],
        suggestions: ['document_type_id' => $typeId]
    );
    callApplyCategory($db, $svcRef, $lowId, $lowResult);

    $stmt = $db->prepare('SELECT document_type_id, classification_confidence FROM documents WHERE id = ?');
    $stmt->execute([$lowId]);
    $lowRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    $stmt2 = $db->prepare("SELECT * FROM classification_suggestions WHERE document_id = ? AND field_code = 'document_type_id'");
    $stmt2->execute([$lowId]);
    $lowSuggestion = $stmt2->fetch(\PDO::FETCH_ASSOC);

    test(
        "Confiance {$lowConfidence} < seuil {$threshold} : document_type_id N'EST PAS applique",
        empty($lowRow['document_type_id']) && $lowRow['classification_confidence'] === null,
        json_encode($lowRow)
    );
    test(
        'Une suggestion pending est tracee (reprenable par un humain)',
        $lowSuggestion !== false && $lowSuggestion['status'] === 'pending' && (int) $lowSuggestion['suggested_value'] === $typeId,
        json_encode($lowSuggestion)
    );

    $db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$lowId]);

    // 2b. Contre-epreuve, verifiee : confiance HAUTE (threshold + 0.1) sur le meme code -> DOIT appliquer.
    //     Si la sonde ne rougissait jamais, ce cas resterait KO. Il doit ici passer AU VERT pour prouver
    //     que le KO ci-dessus mesure vraiment la frontiere, pas un bug de la sonde.
    if ($autoApply) {
        $highId = insertBareProbe($db, 'frontiere_haute_' . uniqid(), '');
        $highConfidence = min(0.99, $threshold + 0.1);
        $highResult = new ClassificationResult(
            category: null,
            tags: [],
            confidence: $highConfidence,
            externalIds: [],
            source: 'frontiere-test',
            raw: [],
            suggestions: ['document_type_id' => $typeId]
        );
        callApplyCategory($db, $svcRef, $highId, $highResult);

        $stmt = $db->prepare('SELECT document_type_id, classification_confidence, last_classified_at, last_classified_by FROM documents WHERE id = ?');
        $stmt->execute([$highId]);
        $highRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        test(
            "CONTRE-EPREUVE verifiee : confiance {$highConfidence} >= seuil {$threshold}, meme code -> document_type_id EST applique cette fois",
            (int) ($highRow['document_type_id'] ?? 0) === $typeId
                && $highRow['classification_confidence'] !== null
                && !empty($highRow['last_classified_at'])
                && !empty($highRow['last_classified_by']),
            json_encode($highRow)
        );

        $db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$highId]);
    } else {
        echo "    (contre-epreuve haute confiance non jouee : classification.auto_apply=false en config —\n";
        echo "     voir test_tri_auto_certitude.php pour cette dimension)\n";
    }
}

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 64) . "\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo str_repeat('=', 64) . "\n";

if ($failed > 0) {
    echo "\n\033[31mLe tri semi-automatique n'est pas prouve fiable.\033[0m\n";
    exit(1);
}

echo "\n\033[32mLe tri semi-automatique est prouve.\033[0m\n";
exit(0);
