<?php

declare(strict_types=1);

namespace Ged\Tests\Workflows;

use Ged\Workflows\Catalogue;
use Ged\Workflows\Conditions;
use PHPUnit\Framework\TestCase;

/**
 * Le catalogue n'expose QUE ce que le moteur GEDv2 exécute réellement. Un nœud de GEDv1 que le moteur
 * ne sait pas exécuter n'y figure pas : il est listé « à porter plus tard ».
 */
final class CatalogueTest extends TestCase
{
    public function testLesCategoriesSontCellesDuMoteur(): void
    {
        self::assertSame(['declencheurs', 'conditions', 'actions', 'etats', 'approbation'], array_keys(Catalogue::tout()));
    }

    public function testLesConditionsExposeesSontExactementCellesDuMoteur(): void
    {
        $champs = [];
        foreach (Catalogue::tout()['conditions'] as $entree) {
            $champs[] = $entree['config']['champ'];
        }

        self::assertSame(Conditions::CHAMPS, array_values(array_unique($champs)));
    }

    public function testLesOperateursExposesSontCeuxDuMoteur(): void
    {
        foreach (Catalogue::tout()['conditions'] as $entree) {
            foreach ($entree['config']['operateurs'] as $operateur) {
                self::assertContains($operateur, Conditions::OPERATEURS);
            }
        }
    }

    public function testLesActionsExposeesSontExactementCellesDuMoteur(): void
    {
        $types = [];
        foreach (array_merge(Catalogue::tout()['actions'], Catalogue::tout()['approbation']) as $entree) {
            $types[] = $entree['config']['action'];
        }

        self::assertSame(['poser', 'etiqueter', 'notifier', 'appeler_jonction', 'demander_validation', 'assigner'], array_values(array_unique($types)));
    }

    public function testChaqueTypeSeRetrouveParType(): void
    {
        $types = Catalogue::types();
        self::assertNotEmpty($types);
        foreach ($types as $type) {
            $entree = Catalogue::parType($type);
            self::assertNotNull($entree, "type exposé : $type");
            self::assertSame($type, $entree['type']);
            self::assertNotSame('', $entree['libelle']);
        }
        self::assertNull(Catalogue::parType('process_ocr'), 'un nœud GEDv1 non exécuté n’est pas exposé');
    }

    public function testLesNoeudsGedV1NonPortesSontListesEtJamaisExposes(): void
    {
        $nonPortes = Catalogue::aPorter();
        self::assertNotEmpty($nonPortes);
        foreach ($nonPortes as $entree) {
            self::assertNotContains($entree['type'], Catalogue::types(), '« à porter » ne doit pas être exposé');
            self::assertNotSame('', $entree['raison']);
        }
        // Les plus emblématiques du catalogue v1 (24 nœuds) sont bien recensés.
        self::assertContains('process_ocr', array_column($nonPortes, 'type'));
        self::assertContains('trigger_scan', array_column($nonPortes, 'type'));
        self::assertContains('timer_delay', array_column($nonPortes, 'type'));
    }
}
