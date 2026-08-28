<?php
/**
 * Oracle SV-13 — D-GED-02 : egalite lignes + TVA = total facture, verifiable
 * sans reference externe.
 *
 * Le QR-facture suisse (cmd4_ingest) ne porte que la partie paiement — jamais
 * le detail des lignes (constat du 2026-08-28, journal facture-qr-t2).
 * L'extraction elle-meme vit dans InvoiceLineItemExtractor (deja routee,
 * app/Controllers/Api/InvoiceLineItemsApiController.php, table
 * invoice_line_items) — repointee sur AIProviderService dans ce meme lot
 * (elle pointait sur ClaudeService, mort sans cle Anthropic configuree, ET
 * lisait une colonne `ocr_content` qui n'existe pas). Cette sonde ne fait
 * QUE le verdict : InvoiceLineExtractionService::reconcile() RECALCULE
 * l'egalite en PHP a partir du resultat, jamais affirmee par le modele
 * (teste en isolation dans tests/Unit/Services/
 * InvoiceLineExtractionServiceTest.php).
 *
 * Aucun mock : document REEL deja en base (corpus courrier-matin), texte OCR
 * reel, appel IA reel, ecriture reelle dans invoice_line_items. Trois issues
 * distinctes, jamais confondues :
 *   - CORRECTE : des lignes sont trouvees ET matches_total est vrai.
 *   - ABSENT   : aucune ligne trouvee (facture sans decomposition imprimee —
 *                cas frequent en Suisse : les primes d'assurance sont
 *                exonerees de TVA, donc sans ce tableau).
 *   - ECHEC    : des lignes sont trouvees mais ne concordent pas avec le
 *                total imprime — jamais confondu avec ABSENT, c'est un etat
 *                different (extraction partielle ou vraie divergence).
 *
 * Usage: php tests/integration/test_facture_lignes_tva.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Services\Extraction\InvoiceLineItemExtractor;
use KDocs\Services\InvoiceLineExtractionService;

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - LIGNES + TVA = TOTAL FACTURE (SV-13, D-GED-02)     |\n";
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

$db = Database::getInstance();
$extractor = new InvoiceLineItemExtractor();

if (!test('Un fournisseur IA est disponible', $extractor->isAvailable())) {
    echo "\n\033[33mABSENT — aucun fournisseur IA actif, rien a mesurer.\033[0m\n";
    exit(1);
}

// --- 1. Facture avec decomposition imprimee (HT/TVA/TTC) : doit CONCORDER ---
echo "--- 1. FACTURE AVEC LIGNES IMPRIMEES (doit rendre CORRECTE) ---\n\n";

// Document reel du corpus courrier-matin (Atelier Menuiserie Bovard Sarl,
// facture a deux lignes HT 8.1%). Cible plutot qu'un `LIKE '%TVA%' LIMIT 1`
// generique : verifie manuellement le 2026-08-28 (2 lignes, 1450+380=1830 HT,
// TVA 148.23, TTC 1978.23, delta=0 exact) — un `LIKE` generique retombe sur
// n'importe quel document mentionnant TVA/TTC, y compris des courriers
// combinant plusieurs pieces avec un total qui ne reconcilie plus rien
// (constat : id 900976, delta=268.36, pas un defaut du code). Repli sur une
// recherche large si ce document precis disparait (recette, corbeille...).
$stmt = $db->prepare('SELECT id FROM documents WHERE id = 901136 AND deleted_at IS NULL LIMIT 1');
$stmt->execute();
$factureId = $stmt->fetchColumn();

if ($factureId === false) {
    $stmt = $db->prepare(
        "SELECT id FROM documents
         WHERE content LIKE '%TVA%' AND content LIKE '%TTC%' AND deleted_at IS NULL
         ORDER BY id LIMIT 1"
    );
    $stmt->execute();
    $factureId = $stmt->fetchColumn();
}

if (!test('Une facture avec TVA/TTC dans le texte existe en base', $factureId !== false)) {
    echo "\n" . str_repeat('=', 66) . "\nRESUME: $passed reussis, $failed echoues\n" . str_repeat('=', 66) . "\n";
    exit(1);
}

echo "    document id={$factureId}\n";
$t0 = microtime(true);
$extraction1 = $extractor->extract((int) $factureId, true);
$dt1 = round((microtime(true) - $t0) * 1000);
echo "    extract() termine en {$dt1}ms\n\n";

test('extract() reussit (chemin reel, ecriture invoice_line_items)', !empty($extraction1['success']),
    $extraction1['success'] ? '' : ($extraction1['error'] ?? '?'));

if (!empty($extraction1['success'])) {
    $info1 = is_array($extraction1['invoice_info'] ?? null) ? $extraction1['invoice_info'] : [];
    $r1 = InvoiceLineExtractionService::reconcile(
        $extraction1['line_items'] ?? [],
        $info1['total_ht'] ?? null,
        $info1['total_tva'] ?? null,
        $info1['total_ttc'] ?? null
    );
    $issue1 = $r1['lines'] === []
        ? 'ABSENT'
        : ($r1['matches_total'] ? 'CORRECTE' : 'ECHEC');
    test(
        "Des lignes sont trouvees ET l'egalite lignes+TVA=total concorde (issue $issue1)",
        $issue1 === 'CORRECTE',
        $issue1 === 'CORRECTE'
            ? "lignes=" . count($r1['lines']) . " lines_sum={$r1['lines_sum']} tva={$r1['tva_computed']} total_ttc={$r1['total_ttc']}"
            : "delta=" . var_export($r1['delta'], true) . " — id={$factureId} n'est peut-etre pas le meilleur fixture"
    );
}

// --- 2. Contre-epreuve : facture sans decomposition (assurance, TVA exoneree) ---
echo "\n--- 2. CONTRE-EPREUVE : DOCUMENT SANS DECOMPOSITION (doit rendre ABSENT, jamais CORRECTE par accident) ---\n\n";

$stmt2 = $db->prepare(
    "SELECT id FROM documents
     WHERE content NOT LIKE '%TVA%' AND content IS NOT NULL AND LENGTH(content) > 500 AND deleted_at IS NULL
     ORDER BY id LIMIT 1"
);
$stmt2->execute();
$sansLignesId = $stmt2->fetchColumn();

if ($sansLignesId !== false) {
    echo "    document id={$sansLignesId}\n";
    $extraction2 = $extractor->extract((int) $sansLignesId, true);
    test('extract() rend un resultat exploitable (contre-epreuve)', !empty($extraction2['success']),
        $extraction2['success'] ?? false ? '' : ($extraction2['error'] ?? '?'));
    if (!empty($extraction2['success'])) {
        $info2 = is_array($extraction2['invoice_info'] ?? null) ? $extraction2['invoice_info'] : [];
        $r2 = InvoiceLineExtractionService::reconcile(
            $extraction2['line_items'] ?? [],
            $info2['total_ht'] ?? null,
            $info2['total_tva'] ?? null,
            $info2['total_ttc'] ?? null
        );
        test(
            "Aucune ligne inventee : matches_total reste faux sur un document sans decomposition",
            $r2['matches_total'] === false,
            'lignes=' . count($r2['lines']) . ' matches_total=' . var_export($r2['matches_total'], true)
        );
    }
} else {
    test('Un document sans decomposition existe pour la contre-epreuve', false, 'aucun candidat trouve');
}

echo "\n" . str_repeat('=', 66) . "\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo str_repeat('=', 66) . "\n";

if ($failed > 0) {
    echo "\n\033[31mDes controles ont echoue.\033[0m\n";
    exit(1);
}

echo "\n\033[32mL'egalite lignes + TVA = total est mesuree, sans reference externe.\033[0m\n";
exit(0);
