<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\EcranMetadonnees;
use Ged\Socle\Nomenclature;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-04 : l'écran Métadonnées est une lecture PURE — un GET n'écrit jamais dans le fonds.
 * Le contrat HTTP et la sonde parcourent les routes déclarées sur des fonds de TEST en lecture
 * seule (tests/corpus…) : y poser la graine au premier regard les écrirait (constaté 30-09).
 * La graine des six types livrés est un ACTE d'administration (action « graine », Nomenclature::assurer).
 */
final class EcranMetadonneesTest extends TestCase
{
    use SupportCorpus;

    protected function setUp(): void
    {
        $this->setUpCorpus();
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testLOuvertureDeLÉcranNEcritJamaisDansLeFonds(): void
    {
        $ecran = new EcranMetadonnees($this->config);
        $donnees = $ecran->donnees([], 'jeton');

        self::assertSame([], $donnees['types'], 'un fonds sans nomenclature se reconnaît : l’écran est vide');
        self::assertTrue($donnees['graineAPoser'], 'et il propose de poser les six types livrés');
        self::assertFileDoesNotExist($this->racine . '/fonds/.ged/nomenclature.json',
            'un GET n’écrit jamais : ni graine, ni dossier .ged');
    }

    public function testAprèsLaGraineLÉcranMontreLesSixTypes(): void
    {
        (new Nomenclature($this->config))->assurer();   // l’acte d’administration (POST action=graine)

        $donnees = (new EcranMetadonnees($this->config))->donnees([], 'jeton');
        self::assertSame(
            ['Facture', 'Note de crédit', 'Reçu', 'Contrat', 'Courrier', 'Document RH'],
            array_column($donnees['types'], 'nom'),
            'l’écran lit la nomenclature du fonds',
        );
        self::assertFalse($donnees['graineAPoser']);
        self::assertSame('facture', $donnees['typeChoisi']['slug'], 'le type affiché en premier se désigne par son slug');
    }
}
