<?php
/**
 * Oracle SV-04 — versionner, par l'effet, en mode FILE SERVER.
 *
 * Olivier : « le versionning : basé sur un dossier windows invisible façon mac
 * et un état des lieux régulier pour avoir une image si il bosse en file
 * server » (2026-08-25). ATTENDUS-PRODUIT A3 (versions rangées À CÔTÉ du
 * fichier, jamais en base) et B8 (contrôle de version).
 *
 * Le chemin réel, pas une réécriture : FilesystemIndexer::upsertDocument()
 * (méthode privée du produit, par réflexion — c'est le code qu'exécute
 * l'indexation). Scénario file server complet :
 *
 *  1. DÉPÔT   : un fichier entre dans le fonds -> ligne documents + archive
 *     v1 (instantané initial) dans .versions/ À CÔTÉ du fichier.
 *  2. MODIFICATION HORS GED : on réécrit le fichier directement sur disque,
 *     comme un utilisateur qui passe par l'explorateur — la GED n'est pas
 *     impliquée.
 *  3. RÉINDEXATION : le hash a changé -> l'ancien état est archivé en v2
 *     AVANT l'écrasement du hash courant, document_versions porte 2 lignes,
 *     is_current a basculé.
 *  4. L'ARBITRE EST LE DISQUE : l'archive v1 contient EXACTEMENT les octets
 *     d'avant la modification (la preuve qu'aucun état n'est perdu), et le
 *     fichier courant reste nu à sa place, ouvrable sans l'application.
 *
 * Usage: php tests/integration/test_versioning_fileserver.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Services\FilesystemIndexer;

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - VERSIONING MODE FILE SERVER (SV-04)               |\n";
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

$indexer = new FilesystemIndexer();
$refUpsert = new ReflectionMethod(FilesystemIndexer::class, 'upsertDocument');
$refUpsert->setAccessible(true);

$basePath = (string) Config::get('storage.base_path', __DIR__ . '/../../storage/documents');
$basePathReal = (string) realpath($basePath);

// ---------------------------------------------------------------------------
// 1. DÉPÔT — le fichier entre dans le fonds par le chemin réel d'indexation.
// ---------------------------------------------------------------------------
echo "--- 1. DÉPÔT (upsertDocument, chemin réel) ---\n\n";

$marqueur = 'sv04_' . uniqid();
$relDir = '_test_probe_versioning';
$relPath = "{$relDir}/{$marqueur}.pdf";
$probeDir = $basePathReal . DIRECTORY_SEPARATOR . $relDir;
if (!is_dir($probeDir)) {
    @mkdir($probeDir, 0755, true);
}
$fullPath = $probeDir . DIRECTORY_SEPARATOR . $marqueur . '.pdf';

$octetsAvant = "CONTENU ORIGINAL SV-04 {$marqueur} — etat de reference";
file_put_contents($fullPath, $octetsAvant);
$hashAvant = md5($octetsAvant);

// Le dossier parent doit exister côté index (upsertFolder par réflexion pour rester sur le chemin réel).
$refUpsertFolder = new ReflectionMethod(FilesystemIndexer::class, 'upsertFolder');
$refUpsertFolder->setAccessible(true);
$folderId = (int) $refUpsertFolder->invoke($indexer, $relDir, null);

$cree = $refUpsert->invoke($indexer, $relPath, $folderId, $fullPath);

$stmt = $db->prepare('SELECT id, checksum, file_path FROM documents WHERE relative_path = ? AND deleted_at IS NULL');
$stmt->execute([$relPath]);
$doc = $stmt->fetch(\PDO::FETCH_ASSOC);
$docId = (int) ($doc['id'] ?? 0);

test('Le fichier indexé devient une ligne documents vivante', $docId > 0, "id={$docId}");
test("Le hash d'origine est enregistré ({$hashAvant})", ($doc['checksum'] ?? '') === $hashAvant, (string) ($doc['checksum'] ?? 'NULL'));

$v1 = $db->prepare('SELECT * FROM document_versions WHERE document_id = ? ORDER BY version_number ASC');
$v1->execute([$docId]);
$versionsDepot = $v1->fetchAll(\PDO::FETCH_ASSOC);

$archiveV1 = (string) ($versionsDepot[0]['file_path'] ?? '');
test(
    "L'instantané initial existe : 1 ligne document_versions, archive À CÔTÉ du fichier dans .versions/",
    count($versionsDepot) === 1
        && str_contains(str_replace('\\', '/', $archiveV1), "/{$relDir}/.versions/")
        && is_file($archiveV1),
    'lignes=' . count($versionsDepot) . ' archive=' . ($archiveV1 ?: 'AUCUNE')
);
test(
    "L'archive v1 contient exactement les octets d'origine",
    is_file($archiveV1) && md5_file($archiveV1) === $hashAvant,
    is_file($archiveV1) ? md5_file($archiveV1) : 'archive absente'
);

// ---------------------------------------------------------------------------
// 2. MODIFICATION HORS GED — l'acte file server pur.
// ---------------------------------------------------------------------------
echo "\n--- 2. MODIFICATION HORS GED (écriture directe sur disque) ---\n\n";

$octetsApres = "CONTENU MODIFIE SV-04 {$marqueur} — passe par l'explorateur, jamais par la GED";
file_put_contents($fullPath, $octetsApres); // la GED n'est pas appelée
$hashApres = md5($octetsApres);

test("Le fichier sur disque porte le nouveau contenu ({$hashApres})", md5_file($fullPath) === $hashApres);

// ---------------------------------------------------------------------------
// 3. RÉINDEXATION — la divergence de hash déclenche l'archivage AVANT mise à jour.
// ---------------------------------------------------------------------------
echo "\n--- 3. RÉINDEXATION (divergence de hash détectée) ---\n\n";

$refUpsert->invoke($indexer, $relPath, $folderId, $fullPath);

$stmt2 = $db->prepare('SELECT checksum FROM documents WHERE id = ?');
$stmt2->execute([$docId]);
$hashCourant = (string) $stmt2->fetchColumn();

$v2 = $db->prepare('SELECT version_number, checksum, file_path, is_current FROM document_versions WHERE document_id = ? ORDER BY version_number ASC');
$v2->execute([$docId]);
$versions = $v2->fetchAll(\PDO::FETCH_ASSOC);

test("Le hash courant en base a suivi le fichier ({$hashApres})", $hashCourant === $hashApres, $hashCourant);
test(
    'document_versions porte l\'HISTORIQUE : 2 versions, v1 = avant, v2 = après',
    count($versions) === 2
        && $versions[0]['checksum'] === $hashAvant
        && $versions[1]['checksum'] === $hashApres,
    'lignes=' . count($versions)
        . ' v1=' . substr((string) ($versions[0]['checksum'] ?? ''), 0, 8)
        . ' v2=' . substr((string) ($versions[1]['checksum'] ?? ''), 0, 8)
);

$courantes = array_filter($versions, static fn ($v) => (int) $v['is_current'] === 1);
test(
    'Une seule version porte is_current (la dernière)',
    count($courantes) === 1 && (int) $versions[1]['version_number'] === (int) reset($courantes)['version_number'],
    'is_current sur v' . implode(',v', array_map(static fn ($v) => $v['version_number'] . '=' . $v['is_current'], $versions))
);

test(
    "L'ARBITRE EST LE DISQUE : l'archive de l'état d'avant contient les octets d'ORIGINE — aucun état perdu",
    is_file($archiveV1) && md5_file($archiveV1) === $hashAvant,
    'preuve que la modification hors GED n\'a détruit aucune donnée'
);
test(
    "Le fichier courant reste nu à sa place, ouvrable sans l'application",
    is_file($fullPath) && md5_file($fullPath) === $hashApres,
    $fullPath
);

// ---------------------------------------------------------------------------
// Nettoyage : la ligne est marquée (zéro suppression), les fichiers sonde
// (artefacts du test, jamais des données du produit) sont retirés du disque.
// ---------------------------------------------------------------------------
$db->prepare('UPDATE documents SET deleted_at = NOW(), checksum = NULL WHERE id = ?')->execute([$docId]);
@unlink($fullPath);
$archiveV2 = (string) ($versions[1]['file_path'] ?? '');
foreach ([$archiveV1, $archiveV2] as $a) {
    if ($a !== '') {
        @unlink($a);
    }
}
@$d = dirname($archiveV1);
@rmdir($d);
@rmdir($probeDir);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 64) . "\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo str_repeat('=', 64) . "\n";

if ($failed > 0) {
    echo "\n\033[31mLe versioning mode file server n'est pas prouve.\033[0m\n";
    exit(1);
}

echo "\n\033[32mLe versioning est prouve par l'effet : depot, modification hors GED, historique intact.\033[0m\n";
exit(0);
