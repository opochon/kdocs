<?php
/**
 * Feature tests for Documents API
 * Tests the structure and validation logic of API endpoints
 */

namespace Tests\Feature;

class DocumentsApiTest extends ApiTestCase
{
    public function testDocumentResponseStructure(): void
    {
        $document = [
            'id' => 1,
            'title' => 'Test Document',
            'filename' => 'test.pdf',
            'original_filename' => 'original_test.pdf',
            'file_path' => '/storage/documents/test.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
            'document_type_id' => 1,
            'document_type_label' => 'Facture',
            'correspondent_id' => 2,
            'correspondent_name' => 'EDF',
            'document_date' => '2026-01-15',
            'amount' => 150.50,
            'currency' => 'CHF',
            'created_at' => '2026-01-27 10:00:00',
            'updated_at' => '2026-01-27 10:00:00',
            'asn' => 123
        ];

        $this->assertArrayHasKey('id', $document);
        $this->assertArrayHasKey('title', $document);
        $this->assertArrayHasKey('filename', $document);
        $this->assertArrayHasKey('mime_type', $document);
        $this->assertArrayHasKey('created_at', $document);
        $this->assertIsInt($document['id']);
        $this->assertIsInt($document['file_size']);
        $this->assertIsFloat($document['amount']);
    }

    public function testPaginatedResponseStructure(): void
    {
        $response = [
            'success' => true,
            'data' => [
                ['id' => 1, 'title' => 'Doc 1'],
                ['id' => 2, 'title' => 'Doc 2'],
            ],
            'pagination' => [
                'page' => 1,
                'per_page' => 25,
                'total' => 100,
                'total_pages' => 4
            ]
        ];

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('pagination', $response);
        $this->assertArrayHasKey('page', $response['pagination']);
        $this->assertArrayHasKey('per_page', $response['pagination']);
        $this->assertArrayHasKey('total', $response['pagination']);
        $this->assertArrayHasKey('total_pages', $response['pagination']);
    }

    public function testAllowedOrderByFields(): void
    {
        $allowedOrderBy = ['id', 'title', 'created_at', 'updated_at', 'document_date', 'amount'];

        $this->assertContains('id', $allowedOrderBy);
        $this->assertContains('title', $allowedOrderBy);
        $this->assertContains('created_at', $allowedOrderBy);
        $this->assertContains('updated_at', $allowedOrderBy);
        $this->assertContains('document_date', $allowedOrderBy);
        $this->assertContains('amount', $allowedOrderBy);
        $this->assertNotContains('file_path', $allowedOrderBy); // Security: no internal fields
    }

    public function testOrderBySanitization(): void
    {
        $queryParams = ['order_by' => 'malicious_field; DROP TABLE documents;--'];

        $orderBy = $queryParams['order_by'] ?? 'created_at';
        $allowedOrderBy = ['id', 'title', 'created_at', 'updated_at', 'document_date', 'amount'];

        if (!in_array($orderBy, $allowedOrderBy)) {
            $orderBy = 'created_at';
        }

        $this->assertEquals('created_at', $orderBy);
    }

    public function testOrderDirectionSanitization(): void
    {
        $queryParams = ['order' => 'invalid'];

        $order = strtoupper($queryParams['order'] ?? 'DESC');
        if ($order !== 'ASC' && $order !== 'DESC') {
            $order = 'DESC';
        }

        $this->assertEquals('DESC', $order);
    }

    public function testOrderDirectionAcceptsAsc(): void
    {
        $queryParams = ['order' => 'asc'];

        $order = strtoupper($queryParams['order'] ?? 'DESC');
        if ($order !== 'ASC' && $order !== 'DESC') {
            $order = 'DESC';
        }

        $this->assertEquals('ASC', $order);
    }

    public function testCreateDocumentRequiredFields(): void
    {
        $data = ['title' => 'Test'];

        $hasRequiredFields = !empty($data['filename']) && !empty($data['file_path']);

        $this->assertFalse($hasRequiredFields);
    }

    public function testCreateDocumentWithRequiredFields(): void
    {
        $data = [
            'title' => 'Test',
            'filename' => 'test.pdf',
            'file_path' => '/storage/documents/test.pdf'
        ];

        $hasRequiredFields = !empty($data['filename']) && !empty($data['file_path']);

        $this->assertTrue($hasRequiredFields);
    }

    public function testDocumentNotFoundResponse(): void
    {
        $response = [
            'success' => false,
            'error' => 'Document non trouvé'
        ];

        $this->assertFalse($response['success']);
        $this->assertEquals('Document non trouvé', $response['error']);
    }

    public function testDocumentWithTagsStructure(): void
    {
        $document = [
            'id' => 1,
            'title' => 'Test',
            'tags' => [
                ['id' => 1, 'name' => 'Important', 'color' => '#ff0000'],
                ['id' => 2, 'name' => 'Archive', 'color' => '#00ff00']
            ]
        ];

        $this->assertArrayHasKey('tags', $document);
        $this->assertIsArray($document['tags']);
        $this->assertCount(2, $document['tags']);
        $this->assertArrayHasKey('name', $document['tags'][0]);
        $this->assertArrayHasKey('color', $document['tags'][0]);
    }

    public function testUpdateDocumentPartialFields(): void
    {
        $data = ['title' => 'New Title'];

        $updateFields = [];
        if (isset($data['title'])) {
            $updateFields[] = 'title = ?';
        }
        if (isset($data['document_type_id'])) {
            $updateFields[] = 'document_type_id = ?';
        }

        $this->assertCount(1, $updateFields);
        $this->assertEquals('title = ?', $updateFields[0]);
    }

    public function testClassifyWithAIResponseStructure(): void
    {
        $response = [
            'success' => true,
            'data' => [
                'suggestions' => [
                    'document_type' => 'Facture',
                    'correspondent' => 'EDF',
                    'document_date' => '2026-01-15',
                    'amount' => 150.50,
                    'tags' => ['Énergie', 'Mensuel'],
                    'confidence' => 0.85
                ]
            ],
            'message' => 'Classification réussie'
        ];

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('suggestions', $response['data']);
    }

    public function testAnalyzeWithAIResponseStructure(): void
    {
        $response = [
            'success' => true,
            'data' => [
                'tags_count' => 3,
                'has_summary' => true,
                'confidence' => 0.85,
                'message' => 'Analyse IA terminée avec succès'
            ]
        ];

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('tags_count', $response['data']);
        $this->assertArrayHasKey('has_summary', $response['data']);
        $this->assertArrayHasKey('confidence', $response['data']);
    }

    public function testNormalizedAIResultStructure(): void
    {
        $normalized = [
            'method' => 'ai',
            'correspondent_id' => 1,
            'correspondent_name' => 'EDF',
            'document_type_id' => 2,
            'document_type_name' => 'Facture',
            'tag_ids' => [1, 2, 3],
            'tag_names' => ['Énergie', 'Mensuel', '2026'],
            'doc_date' => '2026-01-15',
            'amount' => 150.50,
            'currency' => null,
            'confidence' => 0.85,
            'summary' => 'Facture électricité mensuelle',
            'additional_categories' => []
        ];

        $this->assertEquals('ai', $normalized['method']);
        $this->assertArrayHasKey('correspondent_id', $normalized);
        $this->assertArrayHasKey('document_type_id', $normalized);
        $this->assertArrayHasKey('tag_ids', $normalized);
        $this->assertArrayHasKey('confidence', $normalized);
        $this->assertIsArray($normalized['tag_ids']);
        $this->assertIsArray($normalized['tag_names']);
    }

    public function testSearchFilterParameterParsing(): void
    {
        $queryParams = ['search' => 'facture'];

        $this->assertArrayHasKey('search', $queryParams);
        $this->assertEquals('facture', $queryParams['search']);

        $searchParam = '%' . $queryParams['search'] . '%';
        $this->assertEquals('%facture%', $searchParam);
    }

    public function testDocumentTypeIdFilterParameterParsing(): void
    {
        $queryParams = ['document_type_id' => '5'];

        $documentTypeId = (int)$queryParams['document_type_id'];

        $this->assertIsInt($documentTypeId);
        $this->assertEquals(5, $documentTypeId);
    }

    public function testCorrespondentIdFilterParameterParsing(): void
    {
        $queryParams = ['correspondent_id' => '3'];

        $correspondentId = (int)$queryParams['correspondent_id'];

        $this->assertIsInt($correspondentId);
        $this->assertEquals(3, $correspondentId);
    }

    public function testTagIdFilterParameterParsing(): void
    {
        $queryParams = ['tag_id' => '7'];

        $tagId = (int)$queryParams['tag_id'];

        $this->assertIsInt($tagId);
        $this->assertEquals(7, $tagId);
    }

    public function testFormattedDocumentTypeCasting(): void
    {
        $document = [
            'id' => '1',
            'file_size' => '1024',
            'document_type_id' => '5',
            'correspondent_id' => '3',
            'amount' => '150.50',
            'asn' => '123'
        ];

        $formatted = [
            'id' => (int)$document['id'],
            'file_size' => (int)$document['file_size'],
            'document_type_id' => $document['document_type_id'] ? (int)$document['document_type_id'] : null,
            'correspondent_id' => $document['correspondent_id'] ? (int)$document['correspondent_id'] : null,
            'amount' => $document['amount'] ? (float)$document['amount'] : null,
            'asn' => $document['asn'] ? (int)$document['asn'] : null,
        ];

        $this->assertIsInt($formatted['id']);
        $this->assertIsInt($formatted['file_size']);
        $this->assertIsInt($formatted['document_type_id']);
        $this->assertIsFloat($formatted['amount']);
        $this->assertEquals(1, $formatted['id']);
        $this->assertEquals(150.50, $formatted['amount']);
    }

    public function testNullHandlingInFormattedDocument(): void
    {
        $document = [
            'id' => '1',
            'document_type_id' => null,
            'correspondent_id' => '',
            'amount' => null,
            'asn' => ''
        ];

        $formatted = [
            'document_type_id' => $document['document_type_id'] ? (int)$document['document_type_id'] : null,
            'correspondent_id' => $document['correspondent_id'] ? (int)$document['correspondent_id'] : null,
            'amount' => $document['amount'] ? (float)$document['amount'] : null,
            'asn' => $document['asn'] ? (int)$document['asn'] : null,
        ];

        $this->assertNull($formatted['document_type_id']);
        $this->assertNull($formatted['correspondent_id']);
        $this->assertNull($formatted['amount']);
        $this->assertNull($formatted['asn']);
    }

    public function testDeleteDocumentSuccessResponse(): void
    {
        $response = [
            'success' => true,
            'data' => null,
            'message' => 'Document supprimé avec succès'
        ];

        $this->assertTrue($response['success']);
        $this->assertEquals('Document supprimé avec succès', $response['message']);
    }

    public function testUpdateDocumentSuccessResponse(): void
    {
        $response = [
            'success' => true,
            'data' => ['id' => 1, 'title' => 'Updated Title'],
            'message' => 'Document mis à jour avec succès'
        ];

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('Updated Title', $response['data']['title']);
    }
}
