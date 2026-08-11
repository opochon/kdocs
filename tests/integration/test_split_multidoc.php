<?php
/**
 * Oracle du secteur mesurabilite / decoupe multi-documents — SV-16.
 *
 * Olivier (2026-08-10) : « j'ai pas ma decoupe par document [...] je ne sais plus
 * ou on en est. » Cette sonde repond directement : un PDF portant N documents
 * distincts (facture / courrier / contrat, fixture tests/fixtures/probe_multidoc.pdf)
 * produit-il N documents en base, via le chemin reel
 * KDocs\Services\Classification\IngestClassificationService::classify() ->
 * KDocs\Services\PdfSplit\PdfSplitService::detectPageGroups()/split() ->
 * KDocs\Services\PDFSplitterService (aucun mock : aller-retour reel contre le
 * fournisseur IA actif — Infomaniak, ~4-20s par page, mesure le 2026-08-10).
 *
 * Etat mesure en debut de session (Claude en dur, non configure -> return null
 * silencieux) puis reecrit CONCURREMMENT par un autre agent pendant cette meme
 * session (app/Services/PDFSplitterService.php, PdfSplitService.php,
 * IngestClassificationService.php modifies pendant l'investigation — voir
 * `git status`). Cette sonde mesure l'etat REEL au moment ou elle s'execute, pas
 * un etat suppose a l'avance : elle distingue trois issues, avec un message
 * DIFFERENT pour chacune (voir classifySplitOutcome() ci-dessous) :
 *   - CORRECTE   : le nombre de documents crees correspond au nombre de groupes
 *                  de pages attendus.
 *   - ABSENTE    : le detecteur (PDFSplitterService::detectCandidate(), sans
 *                  appel reseau) juge le document non candidat AVANT tout appel
 *                  IA — mono-page, pas un PDF, desactive en config, fichier
 *                  manquant. La decoupe n'a jamais ete tentee.
 *   - ECHEC      : le document EST candidat (multi-page, PDF, active) mais
 *                  l'analyse (IA ou repli heuristique) ne produit pas assez de
 *                  groupes pour separer — le fournisseur IA a pu repondre ou pas,
 *                  le texte extrait a pu etre insuffisant : l'echec est mesure
 *                  APRES tentative, pas avant.
 *
 * Usage: php tests/integration/test_split_multidoc.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Services\Classification\IngestClassificationService;
use KDocs\Services\PDFSplitterService;

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - DECOUPE MULTI-DOCUMENTS (split PDF, SV-16)        |\n";
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

/**
 * Classe l'issue d'un classify() qui a tente une decoupe, a partir de l'etat
 * REEL en base (regle 7 : la sonde tire sa verite d'ailleurs que du code
 * teste) — jamais du seul tableau retourne par classify().
 *
 * @return array{state:string, message:string}
 */
function classifySplitOutcome(\PDO $db, int $parentId, array $classifyResult, int $expectedGroups): array
{
    $detection = $classifyResult['detection'] ?? [];
    $source    = (string) ($detection['source'] ?? '');

    if (empty($detection['should_split'])) {
        return [
            'state' => 'ABSENTE',
            'message' => "decoupe ABSENTE : le detecteur n'a jamais tente l'analyse (source='{$source}', "
                . 'aucun appel IA) — document juge non candidat avant tout aller-retour reseau.',
        ];
    }

    $childIds = $classifyResult['child_documents'] ?? [];
    if (empty($childIds) || !empty($classifyResult['split_error'])) {
        return [
            'state' => 'ECHEC',
            'message' => 'decoupe en ECHEC : le document etait candidat (source=' . $source . ', '
                . ($detection['audit']['pages'] ?? '?') . ' pages) et une analyse a ete tentee '
                . '(IA ou repli heuristique), mais aucun document n\'a pu etre separe.',
        ];
    }

    $stmtCheck = $db->prepare('SELECT COUNT(*) FROM documents WHERE id IN (' . implode(',', array_fill(0, count($childIds), '?')) . ') AND deleted_at IS NULL');
    $stmtCheck->execute(array_map('intval', $childIds));
    $realCount = (int) $stmtCheck->fetchColumn();

    if ($realCount === $expectedGroups) {
        return [
            'state' => 'CORRECTE',
            'message' => "decoupe CORRECTE : {$realCount} document(s) reellement en base (source={$source}), "
                . "conforme aux {$expectedGroups} groupes attendus.",
        ];
    }

    return [
        'state' => 'ECHEC',
        'message' => "decoupe en ECHEC PARTIEL : {$realCount} document(s) crees en base sur {$expectedGroups} attendus "
            . "(classify() en annonce " . count($childIds) . ").",
    ];
}

// ---------------------------------------------------------------------------
// Fixture : 3 pages nettement differentes (facture / courrier / contrat)
// ---------------------------------------------------------------------------
$fixture = realpath(__DIR__ . '/../fixtures/probe_multidoc.pdf');
if (!test('Fixture tests/fixtures/probe_multidoc.pdf presente (3 pages distinctes)', $fixture !== false)) {
    echo "\nRESUME: $passed reussis, $failed echoues\n";
    exit(1);
}

/**
 * Insere un document de test pointant vers $sourcePdf dans un sous-dossier de
 * consume dedie, nettoie les residus d'une execution precedente (marquer,
 * jamais supprimer une ligne), et retourne son id.
 */
function insertProbeDocument(\PDO $db, string $sourcePdf, string $subdir, string $suffix): int
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

    // md5 du contenu reel + suffixe : distingue les executions successives dans
    // le checksum sans jamais toucher au contenu de la fixture versionnee.
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
    $ins->execute([
        'Probe ' . $subdir,
        basename($dest),
        basename($sourcePdf),
        $dest,
        filesize($dest),
        $checksum,
    ]);

    return (int) $db->lastInsertId();
}

// ---------------------------------------------------------------------------
// 1. Chemin reel : IngestClassificationService::classify() sur le PDF a 3 pages
// ---------------------------------------------------------------------------
echo "--- 1. TENTATIVE DE DECOUPE (chemin reel, aucun mock du fournisseur IA) ---\n\n";

$parentId = insertProbeDocument($db, $fixture, '_test_probe_split', '_v1');
echo "    document parent id={$parentId}\n";

$t0 = microtime(true);
$svc = new IngestClassificationService();
$result = $svc->classify($parentId);
$elapsedMs = round((microtime(true) - $t0) * 1000);
echo "    classify() termine en {$elapsedMs}ms\n\n";

$outcome = classifySplitOutcome($db, $parentId, $result, 3);
echo "    " . $outcome['message'] . "\n\n";

test('La decoupe du PDF a 3 documents distincts est CORRECTE', $outcome['state'] === 'CORRECTE', $outcome['message']);

// Verite en base independante du retour de classify() (regle 7).
if ($outcome['state'] === 'CORRECTE') {
    $childIds = array_map('intval', $result['child_documents']);
    $childRows = $db->query(
        'SELECT id, mime_type, file_path FROM documents WHERE id IN (' . implode(',', $childIds) . ')'
    )->fetchAll(\PDO::FETCH_ASSOC);
    $tousPdfSurDisque = true;
    foreach ($childRows as $c) {
        if ($c['mime_type'] !== 'application/pdf' || !file_exists($c['file_path'])) {
            $tousPdfSurDisque = false;
        }
    }
    test('Chaque document separe a un fichier PDF reel sur disque', $tousPdfSurDisque);

    foreach ($childIds as $cid) {
        $db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$cid]);
    }
}
$db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$parentId]);

// ---------------------------------------------------------------------------
// 2. Contre-epreuve 1/2 : un PDF mono-page -> "ABSENTE" (pas d'appel reseau)
// ---------------------------------------------------------------------------
echo "\n--- 2. CONTRE-EPREUVE : PDF mono-page (doit rougir en ABSENTE) ---\n\n";

$fixtureUn = realpath(__DIR__ . '/../fixtures/probe_ingestion.pdf');
if ($fixtureUn !== false) {
    $singleId = insertProbeDocument($db, $fixtureUn, '_test_probe_split_single', '_v1');
    $legacy = new PDFSplitterService();
    $t0 = microtime(true);
    $detection = $legacy->detectCandidate($singleId);
    $dtSingle = round((microtime(true) - $t0) * 1000);

    test(
        'Un PDF mono-page est classe ABSENTE, sans aucun appel IA (rapide, deterministe)',
        ($detection['source'] ?? '') === 'single_page' && $dtSingle < 3000,
        'source=' . ($detection['source'] ?? '?') . " dt={$dtSingle}ms"
    );

    $db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$singleId]);
} else {
    test('Fixture mono-page disponible pour la contre-epreuve ABSENTE', false, 'probe_ingestion.pdf introuvable');
}

// ---------------------------------------------------------------------------
// 3. Contre-epreuve 2/2 : un PDF multi-page SANS texte exploitable -> "ECHEC"
//    (candidat reel, tentative reelle, mais aucune page n'a >= 50 caracteres :
//    analyzePages() ne produit aucune analyse, distinct du cas ABSENTE).
// ---------------------------------------------------------------------------
echo "\n--- 3. CONTRE-EPREUVE : PDF multi-page sans texte (doit rougir en ECHEC) ---\n\n";

$fixtureVide = realpath(__DIR__ . '/../fixtures/probe_multidoc_illisible.pdf');
if ($fixtureVide !== false) {
    $illisibleId = insertProbeDocument($db, $fixtureVide, '_test_probe_split_illisible', '_v1');

    $t0 = microtime(true);
    $svc2 = new IngestClassificationService();
    $result2 = $svc2->classify($illisibleId);
    $dtIllisible = round((microtime(true) - $t0) * 1000);

    $outcome2 = classifySplitOutcome($db, $illisibleId, $result2, 2);
    echo "    dt={$dtIllisible}ms — " . $outcome2['message'] . "\n\n";

    test(
        'Un PDF multi-page sans texte exploitable est classe ECHEC (candidat, tentative, pas de resultat) — message DIFFERENT du cas ABSENTE',
        $outcome2['state'] === 'ECHEC' && $outcome2['message'] !== $outcome['message'],
        $outcome2['message']
    );

    $db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$illisibleId]);
} else {
    test('Fixture multi-page illisible disponible pour la contre-epreuve ECHEC', false, 'probe_multidoc_illisible.pdf introuvable');
}

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 64) . "\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo str_repeat('=', 64) . "\n";

if ($failed > 0) {
    echo "\n\033[31mLa decoupe multi-documents n'est pas prouvee correcte de bout en bout.\033[0m\n";
    exit(1);
}

echo "\n\033[32mLa decoupe multi-documents est prouvee correcte.\033[0m\n";
exit(0);
