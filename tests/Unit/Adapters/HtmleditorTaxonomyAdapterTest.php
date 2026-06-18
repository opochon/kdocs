<?php

namespace KDocs\Tests\Unit\Adapters;

use KDocs\Adapters\HtmleditorTaxonomyAdapter;
use KDocs\Tests\TestCase;

class HtmleditorTaxonomyAdapterTest extends TestCase
{
    public function testLoadExportParsesVariablesSectionsAndExternalIds(): void
    {
        $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ged_taxonomy_fixture_' . uniqid('', true) . '.json';
        $payload = [
            'version' => 2,
            'variables' => [
                'ProductName' => ['value' => 'Stoco', 'externalId' => 'flare.product'],
            ],
            'sets' => ['default' => ['ProductName']],
            'sections' => [['id' => 'intro', 'title' => 'Introduction']],
            'tags' => ['manual', 'stoco'],
            'updatedAt' => '2026-06-18T10:00:00Z',
        ];
        file_put_contents($fixture, json_encode($payload, JSON_UNESCAPED_UNICODE));

        try {
            $adapter = new HtmleditorTaxonomyAdapter();
            $taxonomy = $adapter->loadExport($fixture);

            $this->assertTrue($taxonomy['available']);
            $this->assertSame('Stoco', $taxonomy['variables']['ProductName']['value']);
            $this->assertSame(['manual', 'stoco'], $taxonomy['tags']);
            $this->assertSame('flare.product', $taxonomy['externalIds']['ProductName']);
            $this->assertCount(1, $taxonomy['sections']);
        } finally {
            @unlink($fixture);
        }
    }
}
