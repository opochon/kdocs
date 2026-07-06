<?php
/**
 * Seed de la simulation ERP Connect (GED ↔ K-Time) — spec Playwright erp-connect.
 *
 * Sème dans les DEUX bases (kdocs + k_time, MariaDB locale) un scénario complet :
 *  - K-Time : fournisseur « Fournitout SA » (rôle supplier + IBAN) et 3 articles
 *    avec historique de ventilation distinct — VIS-40 (stock), PREST-INFO (facturé),
 *    CAISSE-ART (vente au comptant) ;
 *  - GED : un document facture (4 lignes dont « CABLE-X » inconnu → non_introduit)
 *    + en-tête invoice_extraction_results (fournisseur, n°, IBAN, échéance, TTC).
 *
 * Idempotent : purge les données marquées ERP-SIM avant de resemer.
 * Usage : php tools/erp-sim-seed.php  → {"document_id": N, "ktime_supplier_id": M}
 */
declare(strict_types=1);

define('KDOCS_ROOT', dirname(__DIR__));
require KDOCS_ROOT . '/vendor/autoload.php';
require_once KDOCS_ROOT . '/app/helpers.php';
\KDocs\Core\Config::load();

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Models\Document;

const SIM_SUPPLIER = 'Fournitout SA';
const SIM_IBAN     = 'CH9300762011623852957';
const SIM_INVOICE  = 'FT-2026-777';
const SIM_MARKER   = 'ERP-SIM';

// ─────────────────────────────────────────────────────────────────────────────
// K-Time (k_time @ MariaDB locale)
// ─────────────────────────────────────────────────────────────────────────────

$kt = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('KTIME_DB_HOST', '127.0.0.1'),
        env('KTIME_DB_PORT', '3307'),
        env('KTIME_DB_NAME', 'k_time')
    ),
    (string) env('KTIME_DB_USER', 'root'),
    (string) env('KTIME_DB_PASS', '')
);
$kt->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Purge sim précédente (ordre FK : lignes → factures → produits → fournisseur)
$oldSupplier = $kt->query("SELECT id FROM clients WHERE name = " . $kt->quote(SIM_SUPPLIER))->fetchColumn();
if ($oldSupplier !== false) {
    $sid = (int) $oldSupplier;
    $kt->exec("DELETE ril FROM received_invoice_lines ril
               JOIN received_invoices ri ON ri.id = ril.received_invoice_id
               WHERE ri.supplier_id = {$sid}");
    $kt->exec("DELETE FROM received_invoices WHERE supplier_id = {$sid}");
    $kt->exec("DELETE FROM product_suppliers WHERE supplier_party_id = {$sid}");
    $kt->exec("DELETE FROM party_bank_accounts WHERE client_id = {$sid}");
    $kt->exec("DELETE FROM party_roles WHERE party_id = {$sid}");
}
$kt->exec("DELETE il FROM invoice_lines il JOIN invoices i ON i.id = il.invoice_id
           WHERE i.invoice_number LIKE 'ERPSIM-%'");
$kt->exec("DELETE FROM invoices WHERE invoice_number LIKE 'ERPSIM-%'");
foreach (['VIS-40', 'PREST-INFO', 'CAISSE-ART'] as $code) {
    $kt->exec("DELETE FROM products WHERE code = " . $kt->quote($code) . " AND description = '" . SIM_MARKER . "'");
}
if ($oldSupplier !== false) {
    $kt->exec("DELETE FROM clients WHERE id = " . (int) $oldSupplier);
}

// Fournisseur
$kt->prepare('INSERT INTO clients (name, ad_numero, active) VALUES (?, 9990, 1)')->execute([SIM_SUPPLIER]);
$supplierId = (int) $kt->lastInsertId();
$kt->prepare("INSERT INTO party_roles (party_id, role) VALUES (?, 'supplier')")->execute([$supplierId]);
$kt->prepare('INSERT INTO party_bank_accounts (client_id, label, iban, is_default) VALUES (?, ?, ?, 1)')
   ->execute([$supplierId, 'Compte principal', SIM_IBAN]);

// Articles (description = marqueur sim pour purge ciblée)
$mkProduct = function (string $code, string $name, int $tracksStock) use ($kt): int {
    $kt->prepare('INSERT INTO products (name, code, tracks_stock, description, active) VALUES (?, ?, ?, ?, 1)')
       ->execute([$name, $code, $tracksStock, SIM_MARKER]);
    return (int) $kt->lastInsertId();
};
$pVis    = $mkProduct('VIS-40', 'Vis inox 40mm', 1);          // → stock
$pPrest  = $mkProduct('PREST-INFO', 'Prestation informatique', 0); // → facture
$pCaisse = $mkProduct('CAISSE-ART', 'Article caisse', 0);     // → vente_comptant

foreach ([[$pVis, 'VIS-40'], [$pPrest, 'PREST-INFO'], [$pCaisse, 'CAISSE-ART']] as [$pid, $ref]) {
    $kt->prepare('INSERT INTO product_suppliers (product_id, supplier_party_id, supplier_ref, is_default, active)
                  VALUES (?, ?, ?, 1, 1)')->execute([$pid, $supplierId, $ref]);
}

// Historique achats : une facture fournisseur passée avec les 3 articles
// (la ventilation n'agrège que les articles déjà achetés chez ce fournisseur).
$kt->prepare("INSERT INTO received_invoices
              (creditor_name, amount, currency, supplier_ref, invoice_date, status, source,
               supplier_id, total_ht, total_tva, total_ttc, note)
              VALUES (?, 500, 'CHF', 'FT-2026-001', '2026-05-10', 'payee', 'manual', ?, 463, 37, 500, ?)")
   ->execute([SIM_SUPPLIER, $supplierId, SIM_MARKER]);
$histId = (int) $kt->lastInsertId();
$histLines = [
    [$pVis, 'Vis inox 40mm', 200, 0.55],
    [$pPrest, 'Prestation informatique', 2, 120.0],
    [$pCaisse, 'Article caisse', 5, 18.0],
];
foreach ($histLines as $i => [$pid, $desc, $qty, $pu]) {
    $kt->prepare('INSERT INTO received_invoice_lines
                  (received_invoice_id, product_id, description, qty, unit_price, total, tva_rate, sort_order)
                  VALUES (?, ?, ?, ?, ?, ?, 8.1, ?)')
       ->execute([$histId, $pid, $desc, $qty, $pu, $qty * $pu, $i]);
}

// Usage aval K-Time → types de ventilation :
//  - PREST-INFO vendu sur facture normale (kind='invoice')
//  - CAISSE-ART vendu en vente au comptant (kind='cash_sale')
$simInvoiceSeq = 0;
$mkSale = function (string $kind, int $productId, string $desc) use ($kt, $supplierId, &$simInvoiceSeq): void {
    $simInvoiceSeq++;
    $kt->prepare("INSERT INTO invoices (invoice_number, client_id, invoice_date, period_from, period_to, kind)
                  VALUES (?, ?, '2026-06-01', '2026-06-01', '2026-06-30', ?)")
       ->execute(['ERPSIM-' . $simInvoiceSeq, $supplierId, $kind]);
    $invId = (int) $kt->lastInsertId();
    $kt->prepare("INSERT INTO invoice_lines (invoice_id, product_id, line_type, description, qty, rate, total)
                  VALUES (?, ?, 'product', ?, 1, 100, 100)")->execute([$invId, $productId, $desc]);
};
$mkSale('invoice', $pPrest, 'Prestation informatique');
$mkSale('cash_sale', $pCaisse, 'Article caisse');

// ─────────────────────────────────────────────────────────────────────────────
// GED (kdocs)
// ─────────────────────────────────────────────────────────────────────────────

$db = Database::getInstance();

// Purge sim précédente côté GED
foreach ($db->query("SELECT id FROM documents WHERE title LIKE 'Facture Fournitout%'")->fetchAll(PDO::FETCH_COLUMN) as $old) {
    $old = (int) $old;
    foreach (['invoice_line_items', 'invoice_extraction_results', 'erp_links'] as $t) {
        try {
            $db->exec("DELETE FROM {$t} WHERE document_id = {$old}");
        } catch (\PDOException) {
            // table absente : ignorer
        }
    }
    $db->exec("DELETE FROM documents WHERE id = {$old}");
}

$folder = 'eval/erp-sim';
$sample = KDOCS_ROOT . '/tests/samples/test.pdf';
$base   = rtrim((string) Config::get('storage.documents'), '/\\');
$dir    = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    fwrite(STDERR, "Impossible de créer $dir\n");
    exit(1);
}
$filename = 'facture_fournitout_' . time() . '.pdf';
$dest = $dir . DIRECTORY_SEPARATOR . $filename;
if (!is_file($sample) || !copy($sample, $dest)) {
    fwrite(STDERR, "Sample PDF introuvable ou copie impossible: $sample\n");
    exit(1);
}

$userId = (int) ($db->query("SELECT id FROM users WHERE username='root' LIMIT 1")->fetchColumn() ?: 1);
$typeId = $db->query("SELECT id FROM document_types WHERE label='Facture' LIMIT 1")->fetchColumn();

$docId = Document::create([
    'title' => 'Facture Fournitout ' . SIM_INVOICE,
    'filename' => $filename,
    'original_filename' => $filename,
    'file_path' => $dest,
    'file_size' => filesize($dest),
    'mime_type' => 'application/pdf',
    'created_by' => $userId,
]);
$db->prepare('UPDATE documents SET relative_path = ?, document_type_id = ? WHERE id = ?')
   ->execute([$folder . '/' . $filename, $typeId ?: null, $docId]);

// Lignes facture (codes alignés sur les articles K-Time ; CABLE-X inconnu → non_introduit)
$gedLines = [
    ['VIS-40',     'Vis inox 40mm',            100, 0.55, 55.0],
    ['PREST-INFO', 'Prestation informatique',    4, 120.0, 480.0],
    ['CAISSE-ART', 'Article caisse',             2, 18.0, 36.0],
    ['CABLE-X',    'Câble mystère 5m',           3, 12.0, 36.0],
];
$ins = $db->prepare('INSERT INTO invoice_line_items
    (document_id, line_number, code, description, quantity, unit_price, tax_rate, line_total)
    VALUES (?, ?, ?, ?, ?, ?, 8.1, ?)');
foreach ($gedLines as $i => [$code, $desc, $qty, $pu, $tot]) {
    $ins->execute([$docId, $i + 1, $code, $desc, $qty, $pu, $tot]);
}

// En-tête (invoice_extraction_results.parsed_data — lu par ErpConnectService::fetchHeader)
$header = [
    'supplier'       => SIM_SUPPLIER,
    'supplier_iban'  => SIM_IBAN,
    'invoice_number' => SIM_INVOICE,
    'invoice_date'   => '2026-07-05',
    'due_date'       => '2026-08-04',
    'total_ttc'      => 655.60,
];
$headerJson = json_encode($header, JSON_UNESCAPED_UNICODE);
$db->prepare('INSERT INTO invoice_extraction_results (document_id, success, parsed_data, raw_response, created_at)
              VALUES (?, 1, ?, ?, NOW())')
   ->execute([$docId, $headerJson, $headerJson]);

$out = ['document_id' => $docId, 'ktime_supplier_id' => $supplierId];
// Fichier d'état pour la spec Playwright (le beforeAll le lit sans resemer —
// un re-seed en cours de run purgerait le draft créé par le test lui-même).
file_put_contents(KDOCS_ROOT . '/tests/visual/.erp-sim.json', json_encode($out));
echo json_encode($out) . PHP_EOL;
