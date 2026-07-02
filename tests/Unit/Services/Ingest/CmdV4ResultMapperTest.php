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
            classification_suggestions TEXT,
            content TEXT,
            content_hash TEXT,
            embedding_status TEXT
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

    public function testApplyAnnexeSubstratePersistsCmdv4Metadata(): void
    {
        $mapper = new CmdV4ResultMapper($this->pdo);
        $applied = $mapper->applyAnnexeSubstrate(7, 'proj-eval', [
            'annexe_md' => '[DOC ID:1] fact sourcé',
            'gate' => 'F0',
        ]);
        $this->assertTrue($applied);

        $doc = $this->pdo->query('SELECT classification_suggestions FROM documents WHERE id = 7')
            ->fetch(PDO::FETCH_ASSOC);
        $suggestions = json_decode((string) $doc['classification_suggestions'], true);
        $this->assertSame('cmd_v4_annexe', $suggestions['method_used']);
        $this->assertSame('proj-eval', $suggestions['cmdv4_slug']);
        $this->assertSame('F0', $suggestions['cmdv4_gate']);
        $this->assertTrue($suggestions['cmdv4_up_to_date']);
    }

    public function testApplyFreshnessStatusUpdatesDocument(): void
    {
        $mapper = new CmdV4ResultMapper($this->pdo);
        $mapper->applyAnnexeSubstrate(7, 'proj-eval', ['annexe_md' => 'x', 'gate' => 'F0']);

        $this->assertTrue($mapper->applyFreshnessStatus(7, [
            'up_to_date' => false,
            'changed' => ['a.pdf'],
        ]));

        $doc = $this->pdo->query('SELECT classification_suggestions FROM documents WHERE id = 7')
            ->fetch(PDO::FETCH_ASSOC);
        $suggestions = json_decode((string) $doc['classification_suggestions'], true);
        $this->assertFalse($suggestions['cmdv4_up_to_date']);
        $this->assertSame(['a.pdf'], $suggestions['cmdv4_freshness']['changed']);
    }

    public function testApplyAnnexeSubstrateIndexeLeContenuPourEmbedding(): void
    {
        $this->pdo->exec("UPDATE documents SET content = 'texte OCR existant', content_hash = 'abc', embedding_status = 'completed' WHERE id = 7");

        $mapper = new CmdV4ResultMapper($this->pdo);
        $mapper->applyAnnexeSubstrate(7, 'proj-eval', [
            'annexe_md' => '[DOC ID:1] fact sourcé annexe',
            'gate' => 'F0',
        ]);

        $doc = $this->pdo->query('SELECT content, content_hash, embedding_status FROM documents WHERE id = 7')
            ->fetch(PDO::FETCH_ASSOC);

        // L'annexe est fusionnée dans le contenu indexable (OCR préservé).
        $this->assertStringContainsString('texte OCR existant', (string) $doc['content']);
        $this->assertStringContainsString('[[CMDV4-ANNEXE]]', (string) $doc['content']);
        $this->assertStringContainsString('fact sourcé annexe', (string) $doc['content']);

        // L'embedding est invalidé → re-vectorisation Qdrant/Ollama au prochain worker.
        $this->assertNull($doc['content_hash']);
        $this->assertSame('pending', $doc['embedding_status']);
    }

    public function testApplyAnnexeSubstrateRemplaceLAnnexePrecedente(): void
    {
        $mapper = new CmdV4ResultMapper($this->pdo);
        $mapper->applyAnnexeSubstrate(7, 'proj-eval', ['annexe_md' => 'version 1', 'gate' => 'F0']);
        $mapper->applyAnnexeSubstrate(7, 'proj-eval', ['annexe_md' => 'version 2', 'gate' => 'F0']);

        $doc = $this->pdo->query('SELECT content FROM documents WHERE id = 7')->fetch(PDO::FETCH_ASSOC);
        $content = (string) $doc['content'];

        $this->assertStringNotContainsString('version 1', $content);
        $this->assertStringContainsString('version 2', $content);
        $this->assertSame(1, substr_count($content, '[[CMDV4-ANNEXE]]'), 'une seule section annexe');
    }
}
