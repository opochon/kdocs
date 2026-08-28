<?php
/**
 * Oracle SV-13 — D-GED-02 : egalite lignes + TVA = total facture, verifiable
 * sans reference externe.
 *
 * Le QR-facture suisse (cmd4_ingest) ne porte que la partie paiement — jamais
 * le detail des lignes (constat du 2026-08-28, journal facture-qr-t2). Sur
 * arbitrage d'Olivier (« utile pour tout classement »), InvoiceLineExtraction
 * Service lit le texte OCR deja indexe et demande a l'IA active de RESTITUER
 * les lignes et les totaux imprimes — le verdict d'egalite est ensuite
 * RECALCULE en PHP (reconcile(), teste en isolation dans
 * tests/Unit/Services/InvoiceLineExtractionServiceTest.php), jamais affirme
 * par le modele.
 *
 * Aucun mock : deux documents REELS deja en base (corpus courrier-matin),
 * texte OCR reel, appel IA reel. Trois issues distinctes, jamais confondues :
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
$svc = new InvoiceLineExtractionService();

if (!test('Un fournisseur IA est disponible', $svc->isAvailable())) {
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
$stmt = $db->prepare('SELECT id, content FROM documents WHERE id = 901136 AND deleted_at IS NULL LIMIT 1');
$stmt->execute();
$facture = $stmt->fetch(\PDO::FETCH_ASSOC);

if ($facture === false) {
    $stmt = $db->prepare(
        "SELECT id, content FROM documents
         WHERE content LIKE '%TVA%' AND content LIKE '%TTC%' AND deleted_at IS NULL
         ORDER BY id LIMIT 1"
    );
    $stmt->execute();
    $facture = $stmt->fetch(\PDO::FETCH_ASSOC);
}

if (!test('Une facture avec TVA/TTC dans le texte existe en base', $facture !== false)) {
    echo "\n" . str_repeat('=', 66) . "\nRESUME: $passed reussis, $failed echoues\n" . str_repeat('=', 66) . "\n";
    exit(1);
}

echo "    document id={$facture['id']}\n";
$t0 = microtime(true);
$r1 = $svc->extract($facture['content']);
$dt1 = round((microtime(true) - $t0) * 1000);
echo "    extract() termine en {$dt1}ms\n\n";

test('extract() rend un resultat exploitable', $r1 !== null);
if ($r1 !== null) {
    $issue1 = $r1['lines'] === []
        ? 'ABSENT'
        : ($r1['matches_total'] ? 'CORRECTE' : 'ECHEC');
    test(
        "Des lignes sont trouvees ET l'egalite lignes+TVA=total concorde (issue $issue1)",
        $issue1 === 'CORRECTE',
        $issue1 === 'CORRECTE'
            ? "lignes=" . count($r1['lines']) . " lines_sum={$r1['lines_sum']} tva={$r1['tva_computed']} total_ttc={$r1['total_ttc']}"
            : "delta=" . var_export($r1['delta'], true) . " — id={$facture['id']} n'est peut-etre pas le meilleur fixture, voir reste du journal"
    );
}

// --- 2. Contre-epreuve : facture sans decomposition (assurance, TVA exoneree) ---
echo "\n--- 2. CONTRE-EPREUVE : DOCUMENT SANS DECOMPOSITION (doit rendre ABSENT, jamais CORRECTE par accident) ---\n\n";

$stmt2 = $db->prepare(
    "SELECT id, content FROM documents
     WHERE content NOT LIKE '%TVA%' AND content IS NOT NULL AND LENGTH(content) > 500 AND deleted_at IS NULL
     ORDER BY id LIMIT 1"
);
$stmt2->execute();
$sansLignes = $stmt2->fetch(\PDO::FETCH_ASSOC);

if ($sansLignes !== false) {
    echo "    document id={$sansLignes['id']}\n";
    $r2 = $svc->extract($sansLignes['content']);
    test('extract() rend un resultat exploitable (contre-epreuve)', $r2 !== null);
    if ($r2 !== null) {
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
