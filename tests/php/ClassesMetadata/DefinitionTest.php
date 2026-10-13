<?php

declare(strict_types=1);

namespace Ged\Tests\ClassesMetadata;

use Ged\ClassesMetadata\Carte;
use Ged\ClassesMetadata\Definition;
use Ged\Socle\Champs;
use Ged\Socle\Listes;
use Ged\Socle\Nomenclature;
use Ged\Socle\Registre;
use PHPUnit\Framework\TestCase;

/**
 * Famille M-Files nº 1 (lot ged-mfiles-classes-1) : la définition des classes et des listes est de la
 * CONFIGURATION PORTABLE et RÉVERSIBLE. Un export versionné (JSON lisible, sans identifiant
 * propriétaire) se réimporte sur un fonds neuf et reproduit la structure à l'identique : ni coffre,
 * ni base propriétaire — le dossier fait foi.
 */
final class DefinitionTest extends TestCase
{
    use SupportClassesMetadata;

    private Definition $definition;
    private Nomenclature $nomenclature;
    private Champs $champs;
    private Listes $listes;

    protected function setUp(): void
    {
        $this->setUpClassesMetadata();
        $this->nomenclature = new Nomenclature($this->config);
        $this->champs = new Champs($this->config);
        $this->listes = new Listes($this->config);
        $this->nomenclature->assurer();
        $this->listes->assurer();
        $this->definition = new Definition($this->config);
    }

    protected function tearDown(): void
    {
        $this->tearDownClassesMetadata();
    }

    private function idDuType(string $nom): int
    {
        return $this->nomenclature->idDuType($nom) ?? self::fail("type « $nom » absent");
    }

    private function viderLaConfiguration(): void
    {
        @unlink($this->racine . '/fonds/.ged/nomenclature.json');
        @unlink($this->racine . '/fonds/.ged/listes.json');
        Registre::oublier();
    }

    public function testUnAllerRetourExportImportReproduitLaDefinitionALIdentique(): void
    {
        $this->nomenclature->ajouter('Mandat');
        $service = $this->listes->ajouter('Service');
        $this->listes->ajouterValeur($service, 'Audit');
        $this->champs->ajouter($this->idDuType('Contrat'), 'Service responsable', 'liste', [], true, 'service');
        $this->champs->ajouter($this->idDuType('Mandat'), 'Montant du mandat', 'montant');

        $export = $this->definition->exporter();
        self::assertSame(1, $export['version'], 'la définition est versionnée');

        $this->viderLaConfiguration();
        $this->definition->importer($export);
        $relu = $this->definition->exporter();

        self::assertSame($export, $relu, 'export → import → export rend la même définition, à l’identique');
    }

    public function testLImportEstIdempotentEtNAjouteNiDoublonNiSuppression(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance', 'date', [], true);
        $export = $this->definition->exporter();

        $this->definition->importer($export);
        $stats = $this->definition->importer($export);

        self::assertSame(0, $stats['classes'], 'un import rejoué ne recrée aucune classe');
        self::assertSame(0, $stats['proprietes'], 'un import rejoué ne recrée aucune propriété');
        self::assertSame($export, $this->definition->exporter());
    }

    public function testLeFichierDeDefinitionEstUnJsonPortableSansIdentifiantProprietaire(): void
    {
        $chemin = $this->racine . '/depots/definition-classes.json';
        $this->champs->ajouter($this->idDuType('Contrat'), 'Service', 'liste', ['Audit', 'Révision']);
        $this->definition->exporterVersFichier($chemin);

        self::assertFileExists($chemin);
        $brut = (string) file_get_contents($chemin);
        $donnees = json_decode($brut, true);
        self::assertIsArray($donnees);
        self::assertArrayHasKey('classes', $donnees);
        self::assertArrayHasKey('listes', $donnees);
        self::assertStringNotContainsString('password', $brut);
        self::assertStringNotContainsString('.ged', $brut, 'la définition ne cite aucun chemin interne');

        $this->viderLaConfiguration();
        $stats = $this->definition->importerFichier($chemin);
        self::assertGreaterThan(0, $stats['classes'] + $stats['proprietes']);
        self::assertNotEmpty((new Carte($this->config))->pourClasse('Contrat')['proprietes']);
    }

    public function testUneListeImporteeAlimenteLaProprieteQuiLaReference(): void
    {
        $service = $this->listes->ajouter('Service');
        $this->listes->ajouterValeur($service, 'Audit');
        $this->champs->ajouter($this->idDuType('Contrat'), 'Service', 'liste', [], false, 'service');
        $export = $this->definition->exporter();

        $this->viderLaConfiguration();
        $this->definition->importer($export);

        $carte = (new Carte($this->config))->pourClasse('Contrat');
        self::assertSame('service', $carte['proprietes'][0]['liste']);
        self::assertSame(['Audit'], $carte['proprietes'][0]['options'],
            'la propriété importée retrouve les valeurs de sa liste nommée importée');
    }
}
