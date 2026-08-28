<?php
/**
 * Oracle SV-12 (partiel) — D-GED-02 : lire le QR d'une facture.
 *
 * D-GED-02 porte quatre clauses. Deux sont BLOQUEES (journal
 * facture-qr-t2, confirme par Olivier) — pas mesurees ici, pas maquillees :
 *   - « verifier que l'adressage est le mien » : aucune notion de « mon
 *     entreprise » (nom/IBAN/adresse du proprietaire du GED) n'existe dans
 *     le schema ou la config.
 *   - « coordonnees du vendeur » (adresse complete) : le QR-facture suisse
 *     ne porte que le NOM de l'emetteur (`issuer`), jamais son adresse
 *     postale — verifie le 2026-08-28, `issuer`/`debtor` sont des chaines
 *     courtes (21/13 caracteres), pas des objets structures.
 *
 * Cette sonde mesure la moitie QUI EST mesurable aujourd'hui, sur decision
 * d'Olivier (« sonde sur la moitie QR disponible ») : la lecture du QR
 * elle-meme (issuer, iban, amount, reference, debtor), deja branchee
 * (Cmd4IngestClient::ingest(), PDFSplitterService::persistInvoiceFacts(),
 * lot ingestion-parc-courriers). Preuve en deux temps :
 *   1. Effet deja produit sur un document reel du corpus (pas un fixture) —
 *      preuve descendante que le pipeline REEL a tourne, pas une simulation.
 *   2. Appel reel a Cmd4IngestClient::ingest() sur un fichier reel, pour
 *      prouver que le CODE ACTUEL (pas seulement un resultat historique)
 *      produit encore ces champs.
 *
 * Usage: php tests/integration/test_facture_qr_lecture.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Services\Ingest\Cmd4IngestClient;

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - LECTURE QR FACTURE (SV-12 partiel, D-GED-02)       |\n";
echo "+==============================================================+\n\n";

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

$requiredFields = ['issuer', 'iban', 'amount', 'reference', 'debtor'];

// --- 1. Effet deja produit : un document reel deja en base porte le QR lu ---
echo "--- 1. EFFET DEJA PRODUIT (document reel, pipeline deja execute) ---\n\n";

// `LIKE '%iban%'` matche des lors que la CLE existe dans le JSON, meme avec
// une valeur vide (le QR-facture n'est pas toujours present sur un document
// par ailleurs correctement classe facture) — filtre donc en PHP sur les
// VALEURS reellement non vides, pas seulement la presence de la cle.
$db = Database::getInstance();
$row = null;
$stmt = $db->query(
    "SELECT id, classification_suggestions FROM documents
     WHERE classification_suggestions LIKE '%iban%' AND deleted_at IS NULL
     ORDER BY id LIMIT 50"
);
foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $candidate) {
    $sugg = json_decode((string) $candidate['classification_suggestions'], true);
    $inv = is_array($sugg['invoice'] ?? null) ? $sugg['invoice'] : [];
    if (!empty($inv['issuer']) && !empty($inv['iban']) && !empty($inv['amount']) && !empty($inv['reference']) && !empty($inv['debtor'])) {
        $row = $candidate;
        break;
    }
}

if (!test('Un document reel avec les 5 champs QR deja lus (non vides) existe en base', $row !== null)) {
    echo "\n\033[33mABSENT — aucun document n'a jamais ete lu completement par le QR-facture sur ce depot.\033[0m\n";
    exit(1);
}

$suggestions = json_decode((string) $row['classification_suggestions'], true);
$invoice1 = is_array($suggestions['invoice'] ?? null) ? $suggestions['invoice'] : [];
echo "    document id={$row['id']}\n";

$missing1 = array_filter($requiredFields, static fn ($f) => empty($invoice1[$f]));
test(
    'Les 5 champs QR sont presents et non vides (issuer, iban, amount, reference, debtor)',
    $missing1 === [],
    $missing1 === [] ? 'tous presents' : 'manquant(s) : ' . implode(',', $missing1)
);

// --- 2. Contre-preuve : le CODE ACTUEL produit encore ces champs (appel reel) ---
echo "\n--- 2. LE CODE ACTUEL LIT ENCORE LE QR (appel reel, aucun mock) ---\n\n";

$client = new Cmd4IngestClient();
if (!test('Le moteur cmd4_ingest est disponible sur ce poste', $client->isAvailable())) {
    echo "\n" . str_repeat('=', 66) . "\nRESUME: $passed reussis, $failed echoues\n" . str_repeat('=', 66) . "\n";
    exit(1);
}

// Facture reelle du corpus (Kuhn Stefan et Marcel), verifiee manuellement
// le 2026-08-28 : issuer/iban/amount/reference/debtor tous lisibles.
$fixture = realpath(__DIR__ . '/../../storage/courrier-matin/documents/2022/Kuhn_Stefan_et_Marcel/Facture/2022-06-23_Kuhn_Stefan_et_Marcel_facture_p082.pdf');

if (!test('La facture de reference existe sur disque', $fixture !== false)) {
    echo "\n" . str_repeat('=', 66) . "\nRESUME: $passed reussis, $failed echoues\n" . str_repeat('=', 66) . "\n";
    exit(1);
}

$outDir = sys_get_temp_dir() . '/cmd4_sv12_' . uniqid();
$t0 = microtime(true);
$result = $client->ingest($fixture, $outDir);
$dt = round((microtime(true) - $t0) * 1000);
echo "    ingest() termine en {$dt}ms\n\n";

$invoice2 = is_array($result['documents'][0]['invoice'] ?? null) ? $result['documents'][0]['invoice'] : [];
$missing2 = array_filter($requiredFields, static fn ($f) => empty($invoice2[$f]));

test(
    "Le QR est lu en direct : les 5 champs sont presents et non vides",
    $missing2 === [],
    $missing2 === [] ? 'tous presents' : 'manquant(s) : ' . implode(',', $missing2)
);

echo "\n" . str_repeat('=', 66) . "\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo str_repeat('=', 66) . "\n";

if ($failed > 0) {
    echo "\n\033[31mDes controles ont echoue.\033[0m\n";
    exit(1);
}

echo "\n\033[32mLa lecture du QR-facture est prouvee — l'adressage et les coordonnees\033[0m\n";
echo "\033[32mvendeur completes restent NON CABLES (voir journal facture-qr-t2).\033[0m\n";
exit(0);
