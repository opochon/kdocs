<?php

declare(strict_types=1);

namespace Ged\Tests\Ui;

use Ged\Socle\Nomenclature;
use Ged\Tests\Socle\SupportCorpus;
use Ged\Ui\ATraiter;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du défaut corrigé : l'écran « À traiter » lisait sa liste de types en passant le PDO au
 * constructeur de Nomenclature (qui attend la Config). Le TypeError était avalé par le `catch`
 * de repli : la nomenclature de l'administrateur n'atteignait JAMAIS l'écran, qui restait figé sur
 * les types de la maquette. Ce test montre le comportement attendu : un type ajouté au fonds
 * apparaît dans les choix de l'écran.
 */
final class ATraiterNomenclatureTest extends TestCase
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

    public function testUnTypeAjouteAuFondsApparaitDansLesChoixDeLEcran(): void
    {
        $nomenclature = new Nomenclature($this->config);
        $nomenclature->assurer();
        $nomenclature->ajouter('Devis');

        $ecran = new ATraiter($this->config, $this->pdo);
        $donnees = $ecran->donnees([], 'jeton-csrf');

        self::assertContains(
            'Devis',
            $donnees['types'],
            'la nomenclature du fonds fait foi : un type ajouté est proposé au contrôleur',
        );
    }
}
