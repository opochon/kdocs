<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\Nomenclature;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-04 §1 : la nomenclature des types est livrée (Facture, Note de crédit, Reçu, Contrat,
 * Courrier, Document RH), lisible par les autres secteurs, et modifiable par l'administrateur —
 * ajouter, renommer, désactiver. Jamais supprimée : des documents vivent avec ces valeurs.
 * Elle vit dans le fonds (`.ged/nomenclature.json`) : zéro table de plus, le plafond de 20 tient.
 */
final class NomenclatureTest extends TestCase
{
    use SupportCorpus;

    private Nomenclature $nomenclature;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        $this->nomenclature = new Nomenclature($this->config);
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testLesSixTypesLivrésSontLisiblesDansLOrdreDuPlan(): void
    {
        $this->nomenclature->assurer();
        self::assertSame(
            ['Facture', 'Note de crédit', 'Reçu', 'Contrat', 'Courrier', 'Document RH'],
            $this->nomenclature->types(),
            'la nomenclature livrée contient exactement les six types, dans l’ordre du plan',
        );
    }

    public function testLaNomenclatureVitDansLeFondsPasDansLaBase(): void
    {
        $this->nomenclature->assurer();
        $this->nomenclature->ajouter('Devis');

        $fichier = $this->racine . '/fonds/.ged/nomenclature.json';
        self::assertFileExists($fichier, 'la nomenclature vit dans le fonds, à côté des documents');
        $surDisque = json_decode((string) file_get_contents($fichier), true) ?: [];
        self::assertSame(
            ['Facture', 'Note de crédit', 'Reçu', 'Contrat', 'Courrier', 'Document RH', 'Devis'],
            array_column($surDisque['types'] ?? [], 'nom'),
            'le fichier dit les types : le dossier fait foi, même pour la configuration',
        );

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['socle_types', 'socle_champs', 'socle_vues'] as $interdite) {
            self::assertNotContains($interdite, $tables,
                "aucune table $interdite : le plafond de 20 tables de l’installateur (oracle V2-01) tient");
        }
    }

    public function testAssurerEstIdempotent(): void
    {
        $this->nomenclature->assurer();
        $this->nomenclature->assurer();
        self::assertCount(6, $this->nomenclature->lister(), 'rejouer assurer ne double pas la nomenclature');
    }

    public function testLAjoutDunTypeParLAdministrateur(): void
    {
        $this->nomenclature->assurer();
        $this->nomenclature->ajouter('Devis');
        self::assertContains('Devis', $this->nomenclature->types());
    }

    public function testUnDoublonSAjoutePasMemeCasseDifférente(): void
    {
        $this->nomenclature->assurer();
        try {
            $this->nomenclature->ajouter('  facture ');
            self::fail('un type homonyme (casse, espaces) devait être refusé');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('existe', $e->getMessage(), 'l’erreur se nomme');
        }
        self::assertCount(6, $this->nomenclature->lister());
    }

    public function testUnNomVideOuTropLongEstRefusé(): void
    {
        $this->nomenclature->assurer();
        foreach (['   ', str_repeat('a', 65)] as $nom) {
            try {
                $this->nomenclature->ajouter($nom);
                self::fail("le nom « $nom » devait être refusé");
            } catch (\RuntimeException $e) {
                self::assertNotSame('', trim($e->getMessage()));
            }
        }
    }

    public function testRenommerEtDésactiverSansJamaisSupprimer(): void
    {
        $this->nomenclature->assurer();
        $id = $this->nomenclature->ajouter('Mandat');

        $this->nomenclature->renommer($id, 'Mandats et procurations');
        self::assertContains('Mandats et procurations', $this->nomenclature->types());

        $this->nomenclature->definirActif($id, false);
        self::assertNotContains('Mandats et procurations', $this->nomenclature->types(),
            'un type désactivé n’est plus proposé (classement, filtres)');
        self::assertContains('Mandats et procurations', array_column($this->nomenclature->lister(false), 'nom'),
            'mais il existe toujours : des documents peuvent vivre avec cette valeur');

        $this->nomenclature->definirActif($id, true);
        self::assertContains('Mandats et procurations', $this->nomenclature->types());
    }

    public function testUnTypeInconnuEstNomméDansLerreur(): void
    {
        $this->nomenclature->assurer();
        try {
            $this->nomenclature->renommer(999999, 'Nouveau nom');
            self::fail('renommer un type inconnu devait échouer');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('999999', $e->getMessage());
        }
    }
}
