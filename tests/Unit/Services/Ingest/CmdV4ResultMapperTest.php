<?php

declare(strict_types=1);

namespace KDocs\Tests\Unit\Services\Ingest;

use KDocs\Services\Ingest\CmdV4ResultMapper;
use KDocs\Tests\TestCase;
use PDO;

class CmdV4ResultMapperTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE documents (
            id INTEGER PRIMARY KEY,
            classification_suggestions TEXT
        )');
        $this->pdo->exec('CREATE TABLE invoice_extraction_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER,
            extraction_type TEXT,
            raw_response TEXT,
            parsed_data TEXT,
            model_used TEXT,
            success INTEGER
        )');
        $this->pdo->exec('CREATE TABLE invoice_line_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER,
            line_number INTEGER,
            quantity REAL,
            unit TEXT,
            code TEXT,
            description TEXT NOT NULL,
            unit_price REAL,
            discount_percent REAL,
            tax_rate REAL,
            tax_amount REAL,
            line_total REAL,
            compte_comptable TEXT,
            centre_cout TEXT,
            projet TEXT,
            raw_text TEXT
        )');
        $this->pdo->exec('INSERT INTO documents (id) VALUES (7)');
    }

    public function testApplyInvoiceFieldsPersistsHeader(): void
    {
        $mapper = new CmdV4ResultMapper($this->pdo);
        $applied = $mapper->applyInvoiceFields(7, [
            'doc_id' => 1,
            'schema' => 'facture_fournisseur',
            'key_complete' => true,
            'fields' => [
                'fournisseur' => 'Swisscom',
                'numero' => 'INV-2026-001',
                'date' => '2026-01-15',
                'montant_ht' => 100.0,
                'montant_ttc' => 107.7,
            ],
        ], 'ged-doc-7');

        $this->assertTrue($applied);

        $row = $this->pdo->query('SELECT parsed_data, model_used FROM invoice_extraction_results WHERE document_id = 7')
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('cmd_v4', $row['model_used']);
        $parsed = json_decode((string) $row['parsed_data'], true);
        $this->assertSame('Swisscom', $parsed['supplier']);

        $doc = $this->pdo->query('SELECT classification_suggestions FROM documents WHERE id = 7')
            ->fetch(PDO::FETCH_ASSOC);
        $suggestions = json_decode((string) $doc['classification_suggestions'], true);
        $this->assertSame('cmd_v4_invoice_schema', $suggestions['method_used']);
    }
}
