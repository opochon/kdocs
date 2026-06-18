<?php

namespace KDocs\Tests\Unit\Services\Classifiers;

use KDocs\Adapters\HtmleditorTaxonomyAdapter;
use KDocs\Contracts\ClassifierInterface;
use KDocs\DTO\ClassificationResult;
use KDocs\Services\Classifiers\UnifiedClassifier;
use KDocs\Tests\TestCase;

class UnifiedClassifierTest extends TestCase
{
    public function testCreateConfiguredRegistersAdapters(): void
    {
        $classifier = UnifiedClassifier::createConfigured();
        $this->assertInstanceOf(UnifiedClassifier::class, $classifier);
        $this->assertSame('unified', $classifier->getName());
        $this->assertTrue($classifier->isAvailable());
    }

    public function testClassifyDocumentUsesTaxonomyAdapter(): void
    {
        $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ged_uc_taxonomy_' . uniqid('', true) . '.json';
        $payload = [
            'tags' => ['manual', 'stoco'],
            'sections' => [['title' => 'Introduction']],
            'externalIds' => ['ProductName' => 'flare.product'],
        ];
        file_put_contents($fixture, json_encode($payload, JSON_UNESCAPED_UNICODE));

        try {
            $taxonomyAdapter = new HtmleditorTaxonomyAdapter();
            $taxonomy = $taxonomyAdapter->loadExport($fixture);

            $stub = new class($taxonomy) implements ClassifierInterface {
                public function __construct(private array $taxonomy) {}
                public function getName(): string { return 'stub-taxonomy'; }
                public function isAvailable(): bool { return true; }
                public function classify(array $context): array
                {
                    return [
                        'category' => 'Introduction',
                        'tags' => ['manual'],
                        'externalIds' => ['ProductName' => 'flare.product'],
                        'confidence' => 0.82,
                        'suggestions' => ['matched_tags' => ['manual']],
                        'raw' => ['taxonomy' => $this->taxonomy],
                    ];
                }
                public function syncTaxonomy(): array { return []; }
            };

            $classifier = new UnifiedClassifier();
            $classifier->registerAdapter($stub);

            $result = $classifier->classifyDocument(
                ['id' => 42, 'title' => 'Guide Stoco manual', 'mime_type' => 'application/pdf'],
                'Guide Stoco manual introduction'
            );

            $this->assertInstanceOf(ClassificationResult::class, $result);
            $this->assertSame('Introduction', $result->category);
            $this->assertContains('manual', $result->tags);
            $this->assertGreaterThanOrEqual(0.75, $result->confidence);
            $this->assertSame('stub-taxonomy', $result->source);
        } finally {
            @unlink($fixture);
        }
    }

    public function testClassifyFallsBackWhenAdaptersBelowThreshold(): void
    {
        $low = new class implements ClassifierInterface {
            public function getName(): string { return 'low-confidence'; }
            public function isAvailable(): bool { return true; }
            public function classify(array $context): array
            {
                return [
                    'category' => null,
                    'tags' => [],
                    'externalIds' => [],
                    'confidence' => 0.2,
                    'suggestions' => [],
                    'raw' => [],
                ];
            }
            public function syncTaxonomy(): array { return []; }
        };

        $classifier = new UnifiedClassifier();
        $classifier->registerAdapter($low);

        $result = $classifier->classify([
            'document_id' => 0,
            'text' => 'facture fournisseur',
        ]);

        $this->assertArrayHasKey('source', $result);
        $this->assertArrayHasKey('confidence', $result);
    }
}
