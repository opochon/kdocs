<?php
/**
 * Oracle S2 — le dossier surveille ne doit PAS apparaitre.
 *
 * Olivier (2026-08-25) : « un dossier est lu regulierement, les pieces splittées
 * analysées, auto classées ou suggérées mais ce dossier ne doit pas apparaitre ».
 * Constat : l'arbre est protege par NOM (InternalFolderRegistry, branche
 * FolderTreeHelper), mais les INDEXEURS (FilesystemIndexer, FilesystemScanner)
 * n'utilisent que storage.ignore_folders — sans 'pending' ni 'consume'. Une
 * indexation complete re-importe donc les fichiers des pieces splittées
 * (storage/documents/pending/, 113 fichiers a la mesure) comme des documents
 * de plus : lignes doublees, compteurs gonfles, dossier interne visible en base.
 *
 * Quatre controles, chemin reel :
 *  1. L'arbre rendu (FolderTreeHelper::render, vrai rendu sur le vrai fonds)
 *     ne contient AUCUN dossier interne (consume, pending, toclassify, ...).
 *  2. FilesystemIndexer consulte InternalFolderRegistry (source de verite
 *     unique) — sa liste d'exclusion contient les noms caches.
 *  3. FilesystemScanner idem.
 *  4. Le VRAI upsertDocument (par reflexion) sur un chemin interne ('pending/…')
 *     ne cree JAMAIS de ligne — meme appele directement, le garde tient.
 *
 * Usage: php tests/integration/test_dossier_surveille_invisible.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Helpers\FolderTreeHelper;
use KDocs\Services\FilesystemIndexer;
use KDocs\Services\FilesystemScanner;
use KDocs\Services\Storage\InternalFolderRegistry;

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - DOSSIER SURVEILLE INVISIBLE (S2)                  |\n";
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

// ---------------------------------------------------------------------------
// 1. ARBRE RENDU — aucun dossier interne dans le HTML reel.
// ---------------------------------------------------------------------------
echo "--- 1. ARBRE (FolderTreeHelper::render, rendu reel) ---\n\n";

$basePath = (string) Config::get('storage.base_path', __DIR__ . '/../../storage/documents');
$tree = new FolderTreeHelper($basePath, Config::basePath() . '/documents');
$html = $tree->render();

preg_match_all('/data-folder-path="([^"]*)"/', $html, $m);
$paths = $m[1] ?? [];
$leaks = [];
foreach ($paths as $p) {
    if (InternalFolderRegistry::isHiddenPath($p)) {
        $leaks[] = $p;
    }
}

test(
    'L\'arbre rendu ne contient aucun dossier interne (' . count(InternalFolderRegistry::hiddenNames()) . ' noms caches)',
    $leaks === [],
    $leaks === []
        ? count($paths) . ' dossier(s) rendu(s), aucun interne'
        : 'fuites: ' . implode(', ', array_slice($leaks, 0, 5))
);

// ---------------------------------------------------------------------------
// 2+3. INDEXEURS — InternalFolderRegistry est la source de verite.
// ---------------------------------------------------------------------------
echo "\n--- 2/3. INDEXEURS (FilesystemIndexer, FilesystemScanner) ---\n\n";

$readIgnore = function (object $obj, string $prop): array {
    $ref = new \ReflectionProperty($obj, $prop);
    $ref->setAccessible(true);
    $val = $ref->getValue($obj);
    return is_array($val) ? $val : [];
};

$indexer   = new FilesystemIndexer();
// FilesystemScanner n'est construit par aucune ligne du produit (etat FANTOME,
// note au journal du lot) — on le construit ici avec la config normalisee en
// tableau, comme son propre constructeur l'exige, pour verifier son garde.
$scanConfig = Config::load();
if (is_string($scanConfig['storage']['allowed_extensions'] ?? null)) {
    $scanConfig['storage']['allowed_extensions'] = array_map('trim', explode(',', $scanConfig['storage']['allowed_extensions']));
}
$scanner   = new FilesystemScanner($scanConfig, $db);
$idxIgnore = $readIgnore($indexer, 'ignoreFolders');
$scnIgnore = $readIgnore($scanner, 'ignoreFolders');

$manquantsIdx = array_diff(['pending', 'consume', 'toclassify', 'processed'], $idxIgnore);
$manquantsScn = array_diff(['pending', 'consume', 'toclassify', 'processed'], $scnIgnore);

test(
    'FilesystemIndexer exclut les dossiers internes (via InternalFolderRegistry)',
    $manquantsIdx === [],
    $manquantsIdx === [] ? count($idxIgnore) . ' noms exclus' : 'manquants: ' . implode(', ', $manquantsIdx)
);
test(
    'FilesystemScanner exclut les dossiers internes (via InternalFolderRegistry)',
    $manquantsScn === [],
    $manquantsScn === [] ? count($scnIgnore) . ' noms exclus' : 'manquants: ' . implode(', ', $manquantsScn)
);

// ---------------------------------------------------------------------------
// 4. GARDE D'ENTREE — le vrai upsertDocument refuse un chemin interne.
// ---------------------------------------------------------------------------
echo "\n--- 4. GARDE (upsertDocument sur chemin interne, code reel) ---\n\n";

$probeRel = 'pending/probe_invisible_' . uniqid() . '.pdf';
$probeFull = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $probeRel);
@mkdir(dirname($probeFull), 0755, true);
file_put_contents($probeFull, '%PDF-1.4 probe-invisible');

$ref = new \ReflectionMethod(FilesystemIndexer::class, 'upsertDocument');
$ref->setAccessible(true);
$created = $ref->invoke($indexer, $probeRel, 0, $probeFull);

$stmt = $db->prepare('SELECT COUNT(*) FROM documents WHERE relative_path = ?');
$stmt->execute([$probeRel]);
$rowCount = (int) $stmt->fetchColumn();

test(
    "upsertDocument('{$probeRel}') ne cree aucune ligne (le garde tient a l'entree)",
    $rowCount === 0,
    "lignes={$rowCount}" . ($created ? ' — retour vrai (insertion tentee)' : '')
);

// Nettoyage du fichier sonde (artefact du test, pas une donnee du produit).
@unlink($probeFull);
if ($rowCount > 0) {
    // Si le garde echoue, marquer la ligne creee — jamais de DELETE.
    $db->prepare('UPDATE documents SET deleted_at = NOW() WHERE relative_path = ?')->execute([$probeRel]);
}

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 64) . "\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo str_repeat('=', 64) . "\n";

if ($failed > 0) {
    echo "\n\033[31mLe dossier surveille ou ses freres internes fuient quelque part.\033[0m\n";
    exit(1);
}

echo "\n\033[32mLe dossier surveille et les dossiers internes restent invisibles.\033[0m\n";
exit(0);
