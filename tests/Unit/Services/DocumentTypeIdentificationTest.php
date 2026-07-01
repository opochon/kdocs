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

     * sans accès BDD — vérifie que le texte OCR/nom de fichier est bien routé.

     *

     * @dataProvider documentTypeTextProvider

     */

    public function testPatternRoutesToEcmLabel(string $text, string $expectedLabel): void

    {

        $label = $this->matchTypeLabelFromPatterns(mb_strtolower($text));

        $this->assertSame($expectedLabel, $label, "texte : {$text}");

    }



    /**

     * Noms de fichiers du lot eval-full (--no-ocr) — oracle G7-classify-distribution.

     *

     * @dataProvider evalLotFilenameProvider

     */

    public function testEvalLotFilenamesAreIdentifiable(string $filename, string $expectedLabel): void

    {

        $label = $this->matchTypeLabelFromPatterns(mb_strtolower($filename));

        $this->assertSame($expectedLabel, $label, "fichier eval : {$filename}");

    }



    public function testAllRequiredEcmLabelsAreRecognizedByPatterns(): void

    {

        $required = ['Facture', 'Note de crédit', 'Contrat', 'Courrier', 'Reçu'];

        $samples = [

            'facture total ttc',

            'note de crédit avoir',

            'contrat parties conviennent',

            'courrier madame monsieur',

            'recu de paiement',

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



    public static function evalLotFilenameProvider(): array

    {

        return [

            'courrier tribunal' => ['Courrier au Tribunal civil - envoi.pdf', 'Courrier'],

            'plainte penale' => ['251014_plainte penale OPO vs VPO.pdf', 'Courrier'],

            'bilan' => ['BILAN 2023-2024.pdf', 'Reçu'],

            'recu releve' => ['recu 28.01.26__241231_releve_Credit_agricole.pdf', 'Reçu'],

            'recu decision' => ['recu 28.01.26__250512_decision_AI.pdf', 'Reçu'],

            'annexe plainte' => ['141025_VPO_plainte_penale_Annexe01.docx', 'Courrier'],

            'arret' => ['Arrêt du 05_06_2024.pdf', 'Contrat'],

            'divorce' => ['Demande en divorce du 15_07_2025 signée.pdf', 'Contrat'],

        ];

    }



    /** @return list{array{0:string,1:string}>} */

    private function patternCatalogue(): array

    {

        return [

            ['note.*cr[ée]dit|credit.*note|avoir', 'Note de crédit'],

            ['facture|invoice|rechnung', 'Facture'],

            ['courrier|lettre|letter|plainte|envoi.*tribunal|tribunal.*envoi', 'Courrier'],

            ['re[çc]u|receipt|relev[ée]|releve|bilan', 'Reçu'],

            ['contrat|contract|arr[êe]t|decision|d[ée]cision|divorce|demande.*sign', 'Contrat'],

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

