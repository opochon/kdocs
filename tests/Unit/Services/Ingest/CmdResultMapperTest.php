<?php

namespace KDocs\Tests\Unit\Services\Ingest;

use KDocs\Services\Ingest\CmdResultMapper;
use KDocs\Tests\TestCase;

class CmdResultMapperTest extends TestCase
{
    public function testApplyAnalysisMarksConfidentResult(): void
    {
        $_ENV['IA_UNIFIED_MIN_CONFIDENCE'] = '0.75';

        $db = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->atLeastOnce())->method('execute');
        $db->method('prepare')->willReturn($stmt);

        $ref = new \ReflectionClass(CmdResultMapper::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($instance, $db);

        $confident = $instance->applyAnalysis(1, [
            'category' => 'facture',
            'tags' => ['comptabilite'],
            'entities' => [['type' => 'montant', 'value' => '100 CHF']],
            'summary' => 'facture — client — 100 CHF',
            'confidence' => 0.91,
        ]);

        $this->assertTrue($confident);
    }
}
