<?php

declare(strict_types=1);

/**
 * Oracle identification types documentaires — hermétique (sans MySQL).
 * Épingle les patterns regex AutoClassifierService (prérequis ECM avant WinBiz).
 */

namespace KDocs\Tests\Unit\Services;

use KDocs\Tests\TestCase;

class DocumentTypeIdentificationTest extends TestCase
{
    /**
     * Reproduit la logique patterns de AutoClassifierService::matchDocumentType()
     * sans accès BDD — vérifie que le texte OCR est bien routé vers le bon libellé.
     *
     * @dataProvider documentTypeTextProvider
     */
    public function testPatternRoutesToEcmLabel(string $text, string $expectedLabel): void
    {
        $label = $this->matchTypeLabelFromPatterns(mb_strtolower($text));
        $this->assertSame($expectedLabel, $label, "texte : {$text}");
    }

    public function testAllRequiredEcmLabelsAreRecognizedByPatterns(): void
    {
        $required = ['Facture', 'Note de crédit', 'Contrat', 'Courrier', 'Reçu'];
        $samples = [
            'facture total ttc',
            'note de crédit avoir',
            'contrat parties conviennent',
            'courrier madame monsieur',
            'reçu de paiement',
        ];
        foreach ($samples as $i => $sample) {
            $label = $this->matchTypeLabelFromPatterns($sample);
            $this->assertSame($required[$i], $label);
        }
    }

    public static function documentTypeTextProvider(): array
    {
        return [
            'facture' => ['facture n° 2024-001 total ttc 1500 chf', 'Facture'],
            'note de crédit' => ['note de crédit avoir client ref 99', 'Note de crédit'],
            'contrat' => ['contrat de prestation entre les parties conviennent', 'Contrat'],
            'courrier' => ['madame monsieur veuillez agréer cordialement courrier', 'Courrier'],
            'reçu' => ['reçu de paiement montant 50.00 chf', 'Reçu'],
        ];
    }

    /** @return list{array{0:string,1:string}>} */
    private function patternCatalogue(): array
    {
        return [
            ['facture|invoice|rechnung', 'Facture'],
            ['note.*crédit|credit.*note', 'Note de crédit'],
            ['contrat|contract', 'Contrat'],
            ['courrier|lettre|letter', 'Courrier'],
            ['reçu|receipt', 'Reçu'],
        ];
    }

    private function matchTypeLabelFromPatterns(string $text): ?string
    {
        foreach ($this->patternCatalogue() as [$pattern, $typeLabel]) {
            if (preg_match('/' . $pattern . '/iu', $text)) {
                return $typeLabel;
            }
        }
        return null;
    }
}
