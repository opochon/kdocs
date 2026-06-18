<?php



namespace KDocs\Tests\Unit\Services\Classification;



use KDocs\Contracts\PdfSplitInterface;

use KDocs\DTO\ClassificationResult;

use KDocs\Services\Classification\IngestClassificationService;

use KDocs\Services\Classifiers\UnifiedClassifier;

use KDocs\Services\Classification\TaxonomySyncService;

use KDocs\Tests\TestCase;



class IngestClassificationServiceTest extends TestCase

{

    public function testClassifyPersistsUnifiedResultWithoutSplit(): void

    {

        $classifier = $this->createMock(UnifiedClassifier::class);

        $classifier->method('classifyDocument')->willReturn(new ClassificationResult(

            category: 'Facture',

            tags: ['comptabilité'],

            confidence: 0.88,

            externalIds: ['InvoiceNo' => 'flare.invoice'],

            source: 'htmleditor-taxonomy',

            raw: ['provider' => 'test'],

            suggestions: ['matched_tags' => ['comptabilité']],

        ));



        $pdfSplit = $this->createMock(PdfSplitInterface::class);

        $pdfSplit->method('detectPageGroups')->willReturn([

            'should_split' => false,

            'page_groups' => [],

            'source' => 'mock',

        ]);



        $taxonomySync = $this->createMock(TaxonomySyncService::class);

        $taxonomySync->method('getStored')->willReturn([

            'taxonomy' => ['tags' => ['comptabilité']],

            'synced_at' => '2026-06-18T12:00:00+00:00',

        ]);



        $service = new IngestClassificationService($classifier, $pdfSplit, $taxonomySync);



        $document = [

            'id' => 900001,

            'title' => 'Facture test',

            'filename' => 'facture-test.pdf',

            'original_filename' => 'facture-test.pdf',

            'content' => 'Facture fournisseur comptabilité',

            'ocr_text' => 'Facture fournisseur comptabilité',

            'mime_type' => 'application/pdf',

            'file_path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ged-test.pdf',

        ];



        $this->persistTestDocument($document);



        try {

            $result = $service->classify(900001);

            $this->assertFalse($result['split']);

            $this->assertSame('Facture', $result['classification']['category']);



            $db = $this->getDb();

            $stmt = $db->prepare('SELECT classification_suggestions FROM documents WHERE id = ?');

            $stmt->execute([900001]);

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $suggestions = json_decode($row['classification_suggestions'] ?? '{}', true);



            $this->assertSame('unified_htmleditor-taxonomy', $suggestions['method_used']);

            $this->assertFalse($suggestions['pending_classification']);

        } finally {

            $this->deleteTestDocument(900001);

        }

    }



    public function testClassifyQueuesChildrenWhenPdfSplitDetected(): void

    {

        $classifier = $this->createMock(UnifiedClassifier::class);

        $pdfSplit = $this->createMock(PdfSplitInterface::class);

        $pdfSplit->method('detectPageGroups')->willReturn([

            'should_split' => true,

            'page_groups' => [[1, 2], [3, 4]],

            'source' => 'clearmydocs-sidecar',

        ]);

        $pdfSplit->method('split')->willReturn([900002, 900003]);



        $taxonomySync = $this->createMock(TaxonomySyncService::class);

        $taxonomySync->method('getStored')->willReturn(null);



        $service = new IngestClassificationService($classifier, $pdfSplit, $taxonomySync);



        $document = [

            'id' => 900004,

            'title' => 'Scan multi-factures',

            'filename' => 'scan-multi.pdf',

            'original_filename' => 'scan-multi.pdf',

            'content' => 'pages multiples',

            'ocr_text' => 'pages multiples',

            'mime_type' => 'application/pdf',

            'file_path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ged-test.pdf',

        ];

        $this->persistTestDocument($document);



        try {

            $result = $service->classify(900004);

            $this->assertTrue($result['split']);

            $this->assertSame([900002, 900003], $result['child_documents']);

        } finally {

            $this->deleteTestDocument(900004);

        }

    }



    /** @param array<string, mixed> $document */

    private function persistTestDocument(array $document): void

    {

        $db = $this->getDb();

        $stmt = $db->prepare('

            INSERT INTO documents (id, title, filename, original_filename, content, ocr_text, mime_type, file_path, status, created_at, updated_at)

            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())

            ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content), ocr_text = VALUES(ocr_text)

        ');

        $stmt->execute([

            $document['id'],

            $document['title'],

            $document['filename'],

            $document['original_filename'],

            $document['content'],

            $document['ocr_text'],

            $document['mime_type'],

            $document['file_path'],

            $document['status'] ?? 'pending',

        ]);

    }



    private function deleteTestDocument(int $id): void

    {

        $db = $this->getDb();

        $db->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);

    }

}

