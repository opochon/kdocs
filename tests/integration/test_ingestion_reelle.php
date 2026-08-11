<?php
/**
 * Oracle du secteur mesurabilite / ingestion — SV-15.
 *
 * Olivier (2026-08-10) : « demain je peux ingerer 1 pdf de 100 pages... je ne sais
 * plus ou on en est. » Cette sonde repond a la premiere brique de la question :
 * un fichier depose par le chemin applicatif reel (storage/consume/ ->
 * ConsumeFolderService::importFile() -> DocumentProcessor::process()) arrive-t-il
 * vraiment en base, sur disque, indexe ?
 *
 * Chemin reel exact, pas un hasMethod : reflection sur la methode PRIVEE
 * ConsumeFolderService::importFile() (le point d'entree reel du scanner de
 * storage/consume/), pas une reecriture du SQL d'insertion. On evite
 * volontairement ConsumeFolderService::scan() (qui parcourt TOUT storage/consume/
 * et aurait importe les fichiers reels d'Olivier qui y attendent deja — zero
 * ecriture sur les donnees reelles) : importFile() est appelee directement sur
 * le seul fichier de la fixture.
 *
 * Piege connu (AGENTS.md) : tests/Feature/DocumentUploadTest.php SKIP en silence
 * des que l'authentification admin/admin echoue et sort VERT sur 0 assertion.
 * Cette sonde ne s'authentifie pas du tout (chemin filesystem, pas HTTP) : elle
 * ne PEUT pas SKIP silencieusement — soit importFile() rend un id exploitable et
 * les assertions s'executent, soit elle leve/timeout et la sonde ROUGIT avec un
 * message explicite.
 *
 * Le chemin complet (ConsumeFolderService::importFile -> DocumentProcessor::process
 * -> IngestEngineRouter) est lance sous timeout (voir REAL_PATH_TIMEOUT_S) : au
 * cours de cette session, un appel direct a ce chemin complet est reste bloque
 * plus de 20 minutes sans jamais rendre la main (cause non identifiee — probe
 * CMD v4, moteur d'ingest natif, ou aucun rapport avec l'IA). Un ordonnanceur
 * absent (AGENTS.md : « aucun ordonnanceur ne tourne ») + un chemin qui bloque
 * seraient la pire combinaison possible pour les 11 fichiers reels actuellement
 * dans storage/consume/. La sonde mesure ce risque au lieu de le contourner.
 *
 * Usage: php tests/integration/test_ingestion_reelle.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Database;

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - INGESTION REELLE (chemin applicatif, SV-15)       |\n";
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

const REAL_PATH_TIMEOUT_S = 90;

// ---------------------------------------------------------------------------
// 0. Nettoyage des residus d'une execution precedente (marquer, jamais supprimer)
// ---------------------------------------------------------------------------
$fixture = realpath(__DIR__ . '/../fixtures/probe_ingestion.pdf');
if ($fixture === false) {
    test('Fixture tests/fixtures/probe_ingestion.pdf presente', false, 'fichier absent — rien a ingerer');
    echo "\nRESUME: $passed reussis, $failed echoues\n";
    exit(1);
}
$checksum = md5_file($fixture);

$stmt = $db->prepare('SELECT id FROM documents WHERE checksum = ?');
$stmt->execute([$checksum]);
foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $oldId) {
    // Marquer et liberer le checksum (jamais de DELETE) pour permettre une reimportation propre.
    $db->prepare('UPDATE documents SET deleted_at = NOW(), checksum = NULL WHERE id = ?')->execute([(int) $oldId]);
}

$probeDir = __DIR__ . '/../../storage/consume/_test_probe_ingestion';
if (!is_dir($probeDir)) {
    @mkdir($probeDir, 0755, true);
}
foreach (glob($probeDir . '/*') ?: [] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}
$dest = $probeDir . '/probe_ingestion.pdf';
copy($fixture, $dest);

// ---------------------------------------------------------------------------
// 1. Le chemin applicatif reel, sous timeout dur
// ---------------------------------------------------------------------------
echo "--- 1. CHEMIN APPLICATIF REEL (ConsumeFolderService::importFile) ---\n\n";

$runner = sys_get_temp_dir() . '/kdocs_probe_ingest_' . uniqid() . '.php';
// Ecrit un script enfant autonome (chemins absolus, independant du cwd du process pere).
$appHelpers = str_replace('\\', '/', realpath(__DIR__ . '/../../app/helpers.php'));
$vendorAutoload = str_replace('\\', '/', realpath(__DIR__ . '/../../vendor/autoload.php'));
$destEsc = str_replace('\\', '/', $dest);
file_put_contents($runner, <<<PHP
<?php
require '{$appHelpers}';
require '{$vendorAutoload}';
use KDocs\Services\ConsumeFolderService;
\$svc = new ConsumeFolderService();
\$ref = new ReflectionMethod(ConsumeFolderService::class, 'importFile');
\$ref->setAccessible(true);
try {
    \$result = \$ref->invoke(\$svc, '{$destEsc}', '_test_probe_ingestion');
    echo json_encode(['ok' => true, 'result' => \$result]);
} catch (\Throwable \$e) {
    echo json_encode(['ok' => false, 'error' => \$e->getMessage()]);
}
PHP
);

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$t0 = microtime(true);
$process = proc_open(['php', $runner], $descriptors, $pipes, __DIR__);

$timedOut = false;
$stdout = '';
if (is_resource($process)) {
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    while (true) {
        $status = proc_get_status($process);
        $stdout .= stream_get_contents($pipes[1]);
        if (!$status['running']) {
            break;
        }
        if ((microtime(true) - $t0) > REAL_PATH_TIMEOUT_S) {
            $timedOut = true;
            proc_terminate($process, 9);
            break;
        }
        usleep(200000);
    }
    $stdout .= stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
@unlink($runner);
$elapsedMs = round((microtime(true) - $t0) * 1000);

test(
    "Le chemin reel termine en moins de " . REAL_PATH_TIMEOUT_S . "s",
    !$timedOut,
    $timedOut
        ? "TIMEOUT apres {$elapsedMs}ms — importFile()/DocumentProcessor::process() n'a pas rendu la main. " .
          "11 fichiers reels attendent dans storage/consume/ (mesure 2026-08-10) : si ce chemin bloque en " .
          "production, ils ne seront jamais traites tant qu'aucun ordonnanceur ne draine la file."
        : "{$elapsedMs}ms"
);

if ($timedOut) {
    echo "\n" . str_repeat('=', 64) . "\n";
    echo "RESUME: $passed reussis, $failed echoues\n";
    echo str_repeat('=', 64) . "\n";
    echo "\n\033[31mLe chemin d'ingestion reel ne termine pas — voir ci-dessus.\033[0m\n";
    exit(1);
}

$decoded = json_decode(trim($stdout), true);
if (!is_array($decoded) || !test('Le runner enfant a produit une reponse JSON exploitable', is_array($decoded), substr((string) $stdout, 0, 300))) {
    echo "\nRESUME: $passed reussis, $failed echoues\n";
    exit(1);
}

if (!test('importFile() ne leve pas d\'exception', $decoded['ok'] ?? false, (string) ($decoded['error'] ?? ''))) {
    echo "\nRESUME: $passed reussis, $failed echoues\n";
    exit(1);
}

$result = $decoded['result'];
$docId  = (int) ($result['id'] ?? 0);
test('Un identifiant de document est retourne', $docId > 0, "id={$docId}");

// ---------------------------------------------------------------------------
// 2. Verite en base — pas le retour de la fonction, la ligne reelle
// ---------------------------------------------------------------------------
echo "\n--- 2. LIGNE EN BASE (verite DB, pas le retour de importFile) ---\n\n";

$stmt = $db->prepare('SELECT * FROM documents WHERE id = ?');
$stmt->execute([$docId]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);

if (!test('Le document existe reellement en base', $row !== false)) {
    echo "\nRESUME: $passed reussis, $failed echoues\n";
    exit(1);
}

test('Le document n\'est pas marque supprime', $row['deleted_at'] === null);
test('Le checksum en base correspond au fichier de la fixture', ($row['checksum'] ?? '') === $checksum, $row['checksum'] ?? '(null)');
test('Le type MIME est application/pdf', ($row['mime_type'] ?? '') === 'application/pdf', $row['mime_type'] ?? '(null)');
test('Le statut n\'est pas "error"', ($row['status'] ?? '') !== 'error', $row['status'] ?? '(null)');

// ---------------------------------------------------------------------------
// 3. Verite sur disque — filesystem-first (ATTENDUS-PRODUIT A1)
// ---------------------------------------------------------------------------
echo "\n--- 3. FICHIER SUR DISQUE (coherence disque <-> base) ---\n\n";

$filePath = (string) ($row['file_path'] ?? '');
$onDisk   = $filePath !== '' && file_exists($filePath);
test('Le file_path pointe vers un fichier qui existe reellement', $onDisk, $filePath);

if ($onDisk) {
    $diskChecksum = md5_file($filePath);
    test(
        'Le contenu sur disque correspond au checksum enregistre',
        $diskChecksum === $checksum,
        "disque={$diskChecksum} attendu={$checksum}"
    );
}

// ---------------------------------------------------------------------------
// 4. Contenu indexe — pas juste "arrive", lisible/exploitable
// ---------------------------------------------------------------------------
echo "\n--- 4. CONTENU INDEXE (OCR/extraction reelle) ---\n\n";

$contentLen = mb_strlen((string) ($row['content'] ?? ''));
$ocrLen     = mb_strlen((string) ($row['ocr_text'] ?? ''));
test(
    'Le document a un contenu indexe exploitable (> 50 caracteres)',
    max($contentLen, $ocrLen) > 50,
    "content={$contentLen} caracteres, ocr_text={$ocrLen} caracteres"
);

// ---------------------------------------------------------------------------
// Teardown : marquer, ne jamais supprimer (regle "zero suppression")
// ---------------------------------------------------------------------------
$db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$docId]);

echo "\n" . str_repeat('=', 64) . "\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo str_repeat('=', 64) . "\n";

if ($failed > 0) {
    echo "\n\033[31mL'ingestion reelle est incomplete ou incoherente.\033[0m\n";
    exit(1);
}

echo "\n\033[32mL'ingestion reelle est prouvee de bout en bout.\033[0m\n";
exit(0);
