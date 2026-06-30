<?php

declare(strict_types=1);

/**
 * Lot 4 — Apprentissage classement étendu : l'AutoClassifier consulte
 * l'historique des corrections utilisateur (TrainingService) pour affiner
 * la pré-suggestion heuristique.
 *
 * Épingle AutoClassifierService::applyLearning() (via classifyRules) :
 *  - une règle apprise confiante (>= 0.7) prend la main sur le type ;
 *  - une règle apprise moins confiante bouche les trous (type absent) ;
 *  - les champs appris (correspondent, tags) bouchent les trous ;
 *  - learning désactivé (CLASSIFY_LEARNING_ENABLED=false) -> aucune consultation.
 *
 * Hermétique : TrainingService mocké (aucun FS), find* stubés (aucun DB).
 */

namespace KDocs\Tests\Unit\Services;

use KDocs\Services\AutoClassifierService;
use KDocs\Services\TrainingService;
use KDocs\Tests\TestCase;
use ReflectionMethod;

class AutoClassifierLearningTest extends TestCase
{
    /** @var list<string> */
    private const ENV_KEYS = ['CLASSIFY_LEARNING_ENABLED'];

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key . '=');
            unset($_ENV[$key]);
        }
    }

    private function enableLearning(): void
    {
        putenv('CLASSIFY_LEARNING_ENABLED=true');
        $_ENV['CLASSIFY_LEARNING_ENABLED'] = 'true';
    }

    private function disableLearning(): void
    {
        putenv('CLASSIFY_LEARNING_ENABLED=false');
        $_ENV['CLASSIFY_LEARNING_ENABLED'] = 'false';
    }

    /**
     * Sous-classe d'AutoClassifierService avec TrainingService mocké + find* stubés.
     * Les méthodes de matching BDD (matchCorrespondent/matchDocumentType/matchTags)
     * sont neutralisées pour ne pas toucher la DB.
     */
    private function makeService(TrainingService $training): AutoClassifierService
    {
        return new class($training) extends AutoClassifierService {
            private TrainingService $t;
            public function __construct(TrainingService $t)
            {
                $this->t = $t;
            }
            protected function makeTrainingService(): TrainingService
            {
                return $this->t;
            }
            // Stub find* -> aucun DB.
            protected function findDocumentTypeByName(string $name): ?array
            {
                return match (mb_strtolower($name)) {
                    'contrat' => ['id' => 2, 'label' => 'Contrat'],
                    'facture' => ['id' => 1, 'label' => 'Facture'],
                    default => null,
                };
            }
            protected function findCorrespondentByName(string $name): ?array
            {
                return match (mb_strtolower($name)) {
                    'acme sarl' => ['id' => 10, 'name' => 'ACME SARL'],
                    default => null,
                };
            }
            protected function findTagByName(string $name): ?array
            {
                return match (mb_strtolower($name)) {
                    'urgence' => ['id' => 99, 'name' => 'urgence'],
                    default => null,
                };
            }
            // Neutralise les matchers BDD appelés par classifyRules.
            public function matchCorrespondent(string $text, array $emails = []): ?array
            {
                return null;
            }
            public function matchDocumentType(string $text, ?string $subfolder = null): ?array
            {
                return null;
            }
            public function matchTags(string $text): array
            {
                return [];
            }
        };
    }

    private function invokeApplyLearning(object $service, array $results, string $text): array
    {
        $m = new ReflectionMethod($service, 'applyLearning');
        $m->setAccessible(true);
        return $m->invokeArgs($service, [$results, $text]);
    }

    public function testLearnedRuleHighConfidenceOverridesEmptyType(): void
    {
        $this->enableLearning();
        $mock = $this->createMock(TrainingService::class);
        $mock->method('applyLearnedRules')->willReturn([
            'type' => 'Contrat',
            'confidence' => 0.8,
            'method' => 'learned_rule',
            'rule_id' => 'rule_x',
            'fields' => ['correspondent' => 'ACME SARL', 'tags' => ['urgence']],
        ]);
        $mock->method('getTrainedClassification')->willReturn(null);

        $service = $this->makeService($mock);
        $results = $this->invokeApplyLearning($service, [
            'method' => 'rules',
            'correspondent_id' => null,
            'document_type_id' => null,
            'tag_ids' => [],
        ], 'texte du contrat');

        $this->assertSame(2, $results['document_type_id']);
        $this->assertSame('Contrat', $results['document_type_name']);
        $this->assertSame('learned_rule', $results['method']);
        $this->assertSame(10, $results['correspondent_id']);
        $this->assertSame('ACME SARL', $results['correspondent_name']);
        $this->assertSame([99], $results['tag_ids']);
        $this->assertSame(['urgence'], $results['tag_names']);
    }

    public function testLearnedRuleLowConfidenceFillsGapButDoesNotOverrideExistingType(): void
    {
        $this->enableLearning();
        $mock = $this->createMock(TrainingService::class);
        $mock->method('applyLearnedRules')->willReturn([
            'type' => 'Contrat',
            'confidence' => 0.55, // < 0.7 -> ne surcharge pas un type existant
            'method' => 'learned_rule',
        ]);
        $mock->method('getTrainedClassification')->willReturn(null);

        $service = $this->makeService($mock);
        $results = $this->invokeApplyLearning($service, [
            'method' => 'rules',
            'document_type_id' => 1, // type déjà trouvé par les règles
            'document_type_name' => 'Facture',
            'correspondent_id' => null,
            'tag_ids' => [],
        ], 'texte');

        // Type existant conservé (confiance apprise < 0.7).
        $this->assertSame(1, $results['document_type_id']);
        $this->assertSame('Facture', $results['document_type_name']);
        $this->assertSame('rules', $results['method']);
    }

    public function testLearnedRuleLowConfidenceFillsEmptyType(): void
    {
        $this->enableLearning();
        $mock = $this->createMock(TrainingService::class);
        $mock->method('applyLearnedRules')->willReturn([
            'type' => 'Contrat',
            'confidence' => 0.55,
            'method' => 'learned_rule',
        ]);
        $mock->method('getTrainedClassification')->willReturn(null);

        $service = $this->makeService($mock);
        $results = $this->invokeApplyLearning($service, [
            'method' => 'rules',
            'document_type_id' => null,
            'correspondent_id' => null,
            'tag_ids' => [],
        ], 'texte');

        // Pas de type existant -> l'apprentissage bouche le trou même peu confiant.
        $this->assertSame(2, $results['document_type_id']);
        $this->assertSame('Contrat', $results['document_type_name']);
        $this->assertSame('learned_rule', $results['method']);
    }

    public function testLearningDisabledDoesNotConsultTraining(): void
    {
        $this->disableLearning();
        $mock = $this->createMock(TrainingService::class);
        $mock->expects($this->never())->method('applyLearnedRules');
        $mock->expects($this->never())->method('getTrainedClassification');

        $service = $this->makeService($mock);
        $results = $this->invokeApplyLearning($service, [
            'method' => 'rules',
            'document_type_id' => null,
            'correspondent_id' => null,
            'tag_ids' => [],
        ], 'texte');

        $this->assertNull($results['document_type_id']);
        $this->assertSame('rules', $results['method']);
    }

    public function testNoLearnedTypeLeavesResultsUnchanged(): void
    {
        $this->enableLearning();
        $mock = $this->createMock(TrainingService::class);
        $mock->method('applyLearnedRules')->willReturn(null);
        $mock->method('getTrainedClassification')->willReturn(null);

        $service = $this->makeService($mock);
        $results = $this->invokeApplyLearning($service, [
            'method' => 'rules',
            'document_type_id' => null,
            'correspondent_id' => null,
            'tag_ids' => [],
        ], 'texte');

        $this->assertNull($results['document_type_id']);
        $this->assertSame('rules', $results['method']);
    }
}
