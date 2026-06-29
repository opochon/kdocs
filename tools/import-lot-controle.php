<?php
/**
 * Importe 10 PDF dans storage/documents (source courante), indexe, classifie, vérifie recherche.
 *
 * Usage : php tools/import-lot-controle.php [--folder=2024/lot-controle] [--clean]
 */
declare(strict_types=1);

define('KDOCS_ROOT', dirname(__DIR__));

require KDOCS_ROOT . '/vendor/autoload.php';
require_once KDOCS_ROOT . '/app/helpers.php';
\KDocs\Core\Config::load();

$folderArg = '2024/lot-controle';
$clean = false;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--folder=')) {
        $folderArg = trim(substr($arg, 9), '/');
    }
    if ($arg === '--clean') {
        $clean = true;
    }
}

$fs = new \KDocs\Services\FilesystemReader();
$basePath = $fs->getBasePath();
$targetRel = $folderArg;
$targetDir = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetRel);

echo "K-Docs — lot contrôle import\n";
echo "Base storage : {$basePath}\n";
echo "Dossier cible : {$targetRel}\n";
echo str_repeat('-', 50) . "\n";

if ($clean && is_dir($targetDir)) {
    foreach (glob($targetDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
    echo "[clean] Fichiers supprimés dans {$targetRel}\n";
}

if ($clean) {
    $db = \KDocs\Core\Database::getInstance();
    $prefix = $targetRel . '/';
    $del = $db->prepare("
        UPDATE documents SET deleted_at = NOW()
        WHERE deleted_at IS NULL
        AND relative_path LIKE ?
        AND relative_path NOT LIKE ?
    ");
    $del->execute([$prefix . '%', $prefix . '%/%']);
    echo '[clean] Entrées BDD archivées (soft delete): ' . $del->rowCount() . "\n";
}

if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
    fwrite(STDERR, "Impossible de créer {$targetDir}\n");
    exit(1);
}

$sample = KDOCS_ROOT . '/tests/samples/test.pdf';
if (!is_file($sample)) {
    $sample = KDOCS_ROOT . '/storage/documents/test.pdf';
}
if (!is_file($sample)) {
    fwrite(STDERR, "Aucun PDF source (tests/samples/test.pdf)\n");
    exit(1);
}

$names = [
    'facture_fournisseur_2024-03-15.pdf',
    'facture_fournisseur_2024-06-01.pdf',
    'contrat_bail_2024-01-10.pdf',
    'courrier_tribunal_2024-02-20.pdf',
    'correspondance_client_2024-04-05.pdf',
    'note_interne_2024-05-12.pdf',
    'scan_facture_2024-07-08.pdf',
    'document_rh_2024-08-19.pdf',
    'recu_paiement_2024-09-30.pdf',
    'archive_2024-11-11.pdf',
];

$copied = 0;
$content = file_get_contents($sample);
if ($content === false) {
    fwrite(STDERR, "Lecture source impossible\n");
    exit(1);
}
foreach ($names as $i => $name) {
    $dest = $targetDir . DIRECTORY_SEPARATOR . $name;
    // Contenu unique par fichier (checksum distinct) — trailer après EOF ignoré par la plupart des lecteurs PDF
    $unique = $content . "\n%% KDocs-lot-controle-" . ($i + 1) . '-' . $name . "\n";
    if (file_put_contents($dest, $unique) === false) {
        fwrite(STDERR, "Échec écriture {$name}\n");
        exit(1);
    }
    $copied++;
}
echo "[OK] {$copied} PDF copiés depuis " . basename($sample) . "\n";

$indexer = new \KDocs\Services\FolderIndexerService();
$indexResult = $indexer->indexFolder($targetRel, false);
if (!($indexResult['success'] ?? false)) {
    fwrite(STDERR, 'Indexation échouée: ' . ($indexResult['error'] ?? 'unknown') . "\n");
    exit(1);
}
echo '[OK] Indexation: indexed=' . ($indexResult['indexed'] ?? 0) . ' total=' . ($indexResult['total'] ?? 0) . "\n";

$db = \KDocs\Core\Database::getInstance();
$stmt = $db->prepare("
    SELECT id, title, original_filename, document_date, content
    FROM documents
    WHERE deleted_at IS NULL
    AND relative_path LIKE ?
    AND relative_path NOT LIKE ?
    ORDER BY id DESC
    LIMIT 20
");
$prefix = $targetRel . '/';
$stmt->execute([$prefix . '%', $prefix . '%/%']);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '[info] Documents BDD dans le lot: ' . count($docs) . "\n";

$ingest = new \KDocs\Services\Classification\IngestClassificationService();
$processor = new \KDocs\Services\DocumentProcessor();
$classifyOk = 0;
foreach ($docs as $doc) {
    $id = (int) $doc['id'];
    try {
        $processor->processDocument($id);
    } catch (\Throwable $e) {
        echo "  [warn] OCR doc {$id}: " . $e->getMessage() . "\n";
    }
    try {
        $ingest->classify($id);
        $classifyOk++;
    } catch (\Throwable $e) {
        echo "  [warn] Classify doc {$id}: " . $e->getMessage() . "\n";
    }
}
echo "[OK] Classification exécutée sur {$classifyOk}/" . count($docs) . " docs\n";

$search = new \KDocs\Services\SearchService();
$q1 = $search->search('facture', 20);
$q2 = $search->search('2024', 20);
echo '[OK] Recherche facture: total=' . ($q1->total ?? 0) . "\n";
echo '[OK] Recherche 2024: total=' . ($q2->total ?? 0) . "\n";

$withDate = 0;
foreach ($docs as $doc) {
    $st = $db->prepare('SELECT document_date FROM documents WHERE id = ?');
    $st->execute([(int) $doc['id']]);
    if ($st->fetchColumn()) {
        $withDate++;
    }
}
echo "[info] document_date renseignée: {$withDate}/" . count($docs) . "\n";

echo "\nContrôle manuel UI:\n";
echo "  1. /kdocs/documents?path=" . urlencode($targetRel) . "\n";
echo "  2. Rechercher « facture » ou « 2024 »\n";
echo "  3. Ouvrir preview sur un document du lot\n";
echo "Terminé.\n";
