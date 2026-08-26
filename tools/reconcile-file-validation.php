<?php
/**
 * Réconciliation ONE-SHOT de la file de validation — lot ingestion 2026-08-25.
 *
 * Deux états de base contraires aux attendus, mesurés par les sondes
 * test_compteurs_coherence.php (S1) et test_smoke_fonctions.php (contrôle 5) :
 *
 *  1. DOUBLONS : 34 groupes de documents vivants « à classer » partageant un
 *     checksum avec un autre vivant — le même fichier physique en file
 *     plusieurs fois (« les x documents à classer x2 »). Origine : semences
 *     eval/probes insérant des lignes directement (MULTI_TEST_*, probe *, lot_d_val).
 *     Traitement : garder UN représentant par groupe (fichier existant en
 *     priorité, sinon id le plus petit), marquer les autres deleted_at —
 *     JAMAIS de DELETE (règle 1 du dépôt).
 *
 *  2. SOUS LE SEUIL : 5 documents vivants last_classified_by='ai' avec
 *     document_type_id posé et confiance < 0.8 — un type imposé sous le seuil
 *     (« classement bâtard », constat Olivier 2026-08-11). Traitement : retirer
 *     le type imposé, poser la suggestion pending correspondante avec la
 *     confiance mesurée, tracer dans classification_audit_log.
 *
 * Usage :
 *   php tools/reconcile-file-validation.php --dry-run   (lecture seule)
 *   php tools/reconcile-file-validation.php             (applique)
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Services\Audit\ClassificationAuditService;

$dryRun = in_array('--dry-run', $argv, true);
$db = Database::getInstance();

echo "\nRéconciliation file de validation — " . ($dryRun ? 'DRY-RUN (rien n\'est écrit)' : 'EXÉCUTION') . "\n";
echo str_repeat('=', 70) . "\n\n";

// ---------------------------------------------------------------------------
// 1. Doublons par checksum parmi les vivants « à classer ».
// ---------------------------------------------------------------------------
$groups = $db->query(
    "SELECT checksum, COUNT(*) AS n FROM documents
     WHERE deleted_at IS NULL AND checksum IS NOT NULL
       AND status IN ('pending', 'needs_review')
     GROUP BY checksum HAVING COUNT(*) > 1"
)->fetchAll(PDO::FETCH_ASSOC);

echo "1. DOUBLONS DE FICHIER PHYSIQUE EN FILE\n";
echo "   groupes en doublon : " . count($groups) . "\n";

$dedupMarked = 0;
foreach ($groups as $g) {
    $rows = $db->prepare(
        "SELECT id, title, file_path FROM documents
         WHERE deleted_at IS NULL AND checksum = ?
         ORDER BY (file_path IS NOT NULL AND file_path != '' ) DESC, id ASC"
    );
    // Ordre : fichier présent en premier — sinon id croissant. MySQL trie les
    // booléens 1 avant 0, ce qui privilege les lignes dont le fichier existe.
    $rows->execute([$g['checksum']]);
    $members = $rows->fetchAll(PDO::FETCH_ASSOC);
    $keep = null;
    foreach ($members as $m) {
        if ($keep === null) {
            $keep = $m;
            continue;
        }
        // On ne garde un second représentant que si le premier n'a pas de
        // fichier — cas impossible avec le tri ci-dessus, la garde reste pour
        // la lisibilité.
        if (!file_exists((string) $keep['file_path']) && file_exists((string) $m['file_path'])) {
            $keep = $m;
            continue;
        }
        if (!$dryRun) {
            $db->prepare('UPDATE documents SET deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL')
                ->execute([$m['id']]);
        }
        $dedupMarked++;
        echo "   - marqué deleted_at : #{$m['id']} « " . mb_substr((string) $m['title'], 0, 48) . " » (garde #{$keep['id']})\n";
    }
}
echo "   lignes marquées     : {$dedupMarked}\n\n";

// ---------------------------------------------------------------------------
// 2. Types imposés sous le seuil.
// ---------------------------------------------------------------------------
$threshold = (float) Config::get('classification.auto_apply_threshold', 0.8);
echo "2. TYPES IMPOSÉS SOUS LE SEUIL ({$threshold})\n";

$violations = $db->query(
    "SELECT id, document_type_id, classification_confidence
     FROM documents
     WHERE deleted_at IS NULL
       AND last_classified_by = 'ai'
       AND document_type_id IS NOT NULL
       AND classification_confidence < {$threshold}"
)->fetchAll(PDO::FETCH_ASSOC);

echo "   documents concernés : " . count($violations) . "\n";

$audit = new ClassificationAuditService();
$fixed = 0;
foreach ($violations as $v) {
    $typeId = (int) $v['document_type_id'];
    $conf = (float) $v['classification_confidence'];
    if (!$dryRun) {
        $db->prepare(
            'UPDATE documents SET document_type_id = NULL, classification_confidence = NULL, last_classified_by = NULL, updated_at = NOW() WHERE id = ?'
        )->execute([$v['id']]);

        // Suggestion reprenable par un humain, avec la confiance réellement mesurée.
        $upd = $db->prepare(
            "UPDATE classification_suggestions SET suggested_value = ?, confidence = ?, source = 'ai'
             WHERE document_id = ? AND field_code = 'document_type_id' AND status = 'pending'"
        );
        $upd->execute([(string) $typeId, $conf, $v['id']]);
        if ($upd->rowCount() === 0) {
            $db->prepare(
                "INSERT INTO classification_suggestions
                    (document_id, field_code, suggested_value, confidence, source, status, created_at)
                 VALUES (?, 'document_type_id', ?, ?, 'ai', 'pending', NOW())"
            )->execute([$v['id'], (string) $typeId, $conf]);
        }

        try {
            $audit->log(
                (int) $v['id'],
                'document_type_id',
                $typeId,
                null,
                'manual',
                ['reason' => 'RECONCILIATION 2026-08-25 : type impose sous le seuil ' . $threshold . ' (confiance ' . $conf . ') — retire, suggestion pendante posee. Lot ingestion.']
            );
        } catch (\Throwable $e) {
            // L'audit ne doit jamais bloquer la réconciliation — la trace
            // principale vit dans le journal du lot.
            echo "   ! audit #{$v['id']} non ecrit : " . $e->getMessage() . "\n";
        }
    }
    $fixed++;
    echo "   - #{$v['id']} : type {$typeId} retiré (confiance {$conf}), suggestion pending posée\n";
}
echo "   documents corrigés : {$fixed}\n\n";

echo str_repeat('=', 70) . "\n";
echo $dryRun ? "DRY-RUN terminé — rien n'a été écrit.\n" : "Réconciliation appliquée. Zéro ligne supprimée (marquage deleted_at uniquement).\n";
exit(0);
