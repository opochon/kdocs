<?php

declare(strict_types=1);

/**
 * Lot 1 — Pipeline « OCR > pré-suggestion heuristique > IA ensuite » + confidence.
 *
 * Épingle la logique PURE ajoutée dans DocumentsApiController::classifyWithAI,
 * sans DB ni appel IA (hermétique) :
 *  - heuristicToSuggestion() : convertit un résultat AutoClassifier en structure
 *    compatible front (champs IA + sous-objet `matched` d'IDs) avec _method='rules'.
 *  - mergeHeuristicIntoAi() : l'IA l'emporte, l'heuristique bouche les trous.
 *  - extractConfidence() : normalise 0..1 (tolère 0..100).
 *  - suggestionHasContent() : détecte une suggestion exploitable.
 *
 * Ces helpers sont privés -> appel par reflection (test unitaire blanc).
 */

namespace KDocs\Tests\Unit\Controllers;

use KDocs\Controllers\Api\DocumentsApiController;
use KDocs\Tests\TestCase;
use ReflectionMethod;

class ClassifyWithAiHeuristicTest extends TestCase
{
    private function invokePrivate(string $name, array $args)
    {
        $ctrl = new DocumentsApiController();
        $m = new ReflectionMethod($ctrl, $name);
        $m->setAccessible(true);
        return $m->invokeArgs($ctrl, $args);
    }

    public function testHeuristicToSuggestionProducesFrontCompatibleStructure(): void
    {
        $heuristic = [
            'method' => 'rules',
            'correspondent_id' => 7,
            'correspondent_name' => 'Tribunal cantonal',
            'document_type_id' => 3,
            'document_type_name' => 'Facture',
            'tag_ids' => [11, 22],
            'tag_names' => ['urgence', 'justice'],
            'doc_date' => '2026-06-30',
            'amount' => 1500.0,
            'currency' => 'CHF',
            'confidence' => 0.8,
        ];

        $s = $this->invokePrivate('heuristicToSuggestion', [$heuristic]);

        $this->assertSame('rules', $s['_method']);
        $this->assertTrue($s['_skipped_ai']);
        $this->assertSame('Tribunal cantonal', $s['correspondent']);
        $this->assertSame('Facture', $s['document_type']);
        $this->assertSame(['urgence', 'justice'], $s['tags']);
        $this->assertSame('2026-06-30', $s['document_date']);
        $this->assertSame(1500.0, $s['amount']);
        $this->assertSame(0.8, $s['confidence']);
        $this->assertSame(7, $s['matched']['correspondent_id']);
        $this->assertSame(3, $s['matched']['document_type_id']);
        $this->assertSame([11, 22], $s['matched']['tag_ids']);
    }

    public function testMergeHeuristicIntoAiFillsGapsLeftByAi(): void
    {
        // L'IA a trouvé le correspondant mais ni type ni tags ; l'heuristique
        // a trouvé un type + des tags -> le merge bouche les trous, l'IA garde
        // la priorité sur le correspondant.
        $ai = [
            'correspondent' => 'Notaire Dupont',
            'document_type' => null,
            'tags' => [],
            'document_date' => null,
            'amount' => null,
            'confidence' => 0.9,
            'matched' => [
                'correspondent_id' => 5,
                'document_type_id' => null,
                'tag_ids' => [],
            ],
        ];
        $heuristic = [
            'method' => 'rules',
            'correspondent_id' => 99, // ignoré : l'IA a déjà 5
            'correspondent_name' => 'Autre',
            'document_type_id' => 3,
            'document_type_name' => 'Facture',
            'tag_ids' => [11],
            'tag_names' => ['urgence'],
            'doc_date' => '2026-01-15',
            'amount' => 200.0,
            'currency' => 'CHF',
            'confidence' => 0.6,
        ];

        $merged = $this->invokePrivate('mergeHeuristicIntoAi', [$ai, $heuristic]);

        // Priorité IA conservée sur le correspondant.
        $this->assertSame(5, $merged['matched']['correspondent_id']);
        $this->assertSame('Notaire Dupont', $merged['correspondent']);
        // Trous bouchés par l'heuristique.
        $this->assertSame(3, $merged['matched']['document_type_id']);
        $this->assertSame('Facture', $merged['document_type']);
        $this->assertSame([11], $merged['matched']['tag_ids']);
        $this->assertSame(['urgence'], $merged['tags']);
        $this->assertSame('2026-01-15', $merged['document_date']);
        $this->assertSame(200.0, $merged['amount']);
        $this->assertSame('ai+rules', $merged['_method']);
        $this->assertSame(0.6, $merged['_heuristic_confidence']);
    }

    public function testExtractConfidenceNormalizesRange(): void
    {
        $this->assertSame(0.0, $this->invokePrivate('extractConfidence', [['confidence' => 0]]));
        $this->assertSame(1.0, $this->invokePrivate('extractConfidence', [['confidence' => 1]]));
        $this->assertSame(0.85, $this->invokePrivate('extractConfidence', [['confidence' => 0.85]]));
        // 0..100 toléré -> ramené à 0..1
        $this->assertSame(0.8, $this->invokePrivate('extractConfidence', [['confidence' => 80]]));
        $this->assertSame(1.0, $this->invokePrivate('extractConfidence', [['confidence' => 100]]));
        // défaut 0 si absent
        $this->assertSame(0.0, $this->invokePrivate('extractConfidence', [[]]));
    }

    public function testSuggestionHasContentDetectsExploitableFields(): void
    {
        $doc = ['id' => 1];
        $empty = ['matched' => ['correspondent_id' => null, 'document_type_id' => null, 'tag_ids' => []]];
        $this->assertFalse($this->invokePrivate('suggestionHasContent', [$empty, $doc, 1]));

        $withType = ['matched' => ['correspondent_id' => null, 'document_type_id' => 3, 'tag_ids' => []]];
        $this->assertTrue($this->invokePrivate('suggestionHasContent', [$withType, $doc, 1]));

        $withDate = ['matched' => ['correspondent_id' => null, 'document_type_id' => null, 'tag_ids' => []], 'document_date' => '2026-06-30'];
        $this->assertTrue($this->invokePrivate('suggestionHasContent', [$withDate, $doc, 1]));

        $withTitle = ['matched' => ['correspondent_id' => null, 'document_type_id' => null, 'tag_ids' => []], 'title_suggestion' => 'Facture ACME'];
        $this->assertTrue($this->invokePrivate('suggestionHasContent', [$withTitle, $doc, 1]));
    }
}
