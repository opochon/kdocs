<?php

declare(strict_types=1);

/**
 * Lot 3 — Auto-apprentissage sur correction utilisateur.
 *
 * Épingle que DocumentsApiController::recordClassificationCorrection() :
 *  - enregistre une correction dans TrainingService quand l'utilisateur change
 *    le type d'un document (suggestedType = ancien, correctedType = nouveau),
 *    avec le texte OCR et les champs corrigés (correspondent, tags) ;
 *  - n'enregistre RIEN quand CLASSIFY_LEARNING_ENABLED != 'true' (tests) ;
 *  - n'enregistre RIEN si ni le type ni le correspondant ne changent ;
 *  - n'enregistre RIEN si le document n'a pas de type final ni de texte OCR.
 *
 * Hermétique : TrainingService mocké (aucun FS), resolvers stubés (aucun DB).
 */

namespace KDocs\Tests\Unit\Controllers;

use KDocs\Controllers\Api\DocumentsApiController;
use KDocs\Services\TrainingService;
use KDocs\Tests\TestCase;
use ReflectionMethod;

class ClassifyLearningCorrectionTest extends TestCase
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
     * Construit un contrôleur dont la factory TrainingService + les resolvers
     * sont stubés : aucun appel DB ni filesystem.
     */
    private function makeControllerWithMockTraining(TrainingService $training): DocumentsApiController
    {
        return new class($training) extends DocumentsApiController {
            private TrainingService $t;
            public function __construct(TrainingService $t)
            {
                $this->t = $t;
            }
            protected function makeTrainingService(): TrainingService
            {
                return $this->t;
            }
            protected function resolveDocumentTypeLabel(?int $typeId): ?string
            {
                return match ($typeId) {
                    1 => 'Facture',
                    2 => 'Contrat',
                    default => null,
                };
            }
            protected function resolveCorrespondentName(?int $corrId): ?string
            {
                return match ($corrId) {
                    10 => 'ACME SARL',
                    default => null,
                };
            }
            protected function resolveDocumentTagNames(int $id): array
            {
                return $id === 42 ? ['urgence'] : [];
            }
        };
    }

    private function invokeRecord(object $ctrl, int $id, array $before, array $after): void
    {
        $m = new ReflectionMethod($ctrl, 'recordClassificationCorrection');
        $m->setAccessible(true);
        $m->invokeArgs($ctrl, [$id, $before, $after]);
    }

    public function testTypeChangeRecordsCorrectionWithSuggestedAndCorrectedType(): void
    {
        $this->enableLearning();
        $mock = $this->createMock(TrainingService::class);
        $mock->expects($this->once())->method('storeCorrection')
            ->willReturnCallback(function (string $text, string $suggested, string $corrected, array $fields, ?int $docId): bool {
                $this->assertSame('Texte OCR du document', $text);
                $this->assertSame('Facture', $suggested);
                $this->assertSame('Contrat', $corrected);
                $this->assertSame(10, $fields['correspondent']);
                $this->assertSame(['urgence'], $fields['tags']);
                $this->assertSame('type', $fields['correction_kind']);
                $this->assertSame(42, $docId);
                return true;
            });

        $ctrl = $this->makeControllerWithMockTraining($mock);
        $this->invokeRecord($ctrl, 42,
            ['document_type_id' => 1, 'correspondent_id' => null, 'content' => 'Texte OCR du document'],
            ['document_type_id' => 2, 'correspondent_id' => 10]
        );
    }

    public function testCorrespondentOnlyChangeStillRecordsWithCurrentType(): void
    {
        $this->enableLearning();
        $mock = $this->createMock(TrainingService::class);
        $mock->expects($this->once())->method('storeCorrection')
            ->willReturnCallback(function (string $text, string $suggested, string $corrected, array $fields, ?int $docId): bool {
                // Type inchangé : suggested = corrected = type courant.
                $this->assertSame('Facture', $suggested);
                $this->assertSame('Facture', $corrected);
                $this->assertSame('correspondent', $fields['correction_kind']);
                $this->assertSame(10, $fields['correspondent']);
                return true;
            });

        $ctrl = $this->makeControllerWithMockTraining($mock);
        $this->invokeRecord($ctrl, 42,
            ['document_type_id' => 1, 'correspondent_id' => null, 'ocr_text' => 'Texte OCR'],
            ['document_type_id' => 1, 'correspondent_id' => 10]
        );
    }

    public function testNoChangeDoesNotRecord(): void
    {
        $this->enableLearning();
        $mock = $this->createMock(TrainingService::class);
        $mock->expects($this->never())->method('storeCorrection');

        $ctrl = $this->makeControllerWithMockTraining($mock);
        $this->invokeRecord($ctrl, 42,
            ['document_type_id' => 1, 'correspondent_id' => 10, 'content' => 'Texte'],
            ['document_type_id' => 1, 'correspondent_id' => 10]
        );
    }

    public function testLearningDisabledDoesNotRecord(): void
    {
        $this->disableLearning();
        $mock = $this->createMock(TrainingService::class);
        $mock->expects($this->never())->method('storeCorrection');

        $ctrl = $this->makeControllerWithMockTraining($mock);
        $this->invokeRecord($ctrl, 42,
            ['document_type_id' => 1, 'correspondent_id' => null, 'content' => 'Texte'],
            ['document_type_id' => 2, 'correspondent_id' => 10]
        );
    }

    public function testNoFinalTypeDoesNotRecord(): void
    {
        $this->enableLearning();
        $mock = $this->createMock(TrainingService::class);
        $mock->expects($this->never())->method('storeCorrection');

        $ctrl = $this->makeControllerWithMockTraining($mock);
        // Type retiré (avant 1 -> après null) : pas de classement final -> rien à apprendre.
        $this->invokeRecord($ctrl, 42,
            ['document_type_id' => 1, 'content' => 'Texte'],
            ['document_type_id' => null]
        );
    }

    public function testNoOcrTextDoesNotRecord(): void
    {
        $this->enableLearning();
        $mock = $this->createMock(TrainingService::class);
        $mock->expects($this->never())->method('storeCorrection');

        $ctrl = $this->makeControllerWithMockTraining($mock);
        $this->invokeRecord($ctrl, 42,
            ['document_type_id' => 1, 'content' => '', 'ocr_text' => ''],
            ['document_type_id' => 2]
        );
    }
}
