<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Parite\Juge;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du juge de parite (lot ged-oracle-paperless-1) sur un mini-inventaire de fixture.
 * Chaque cas correspond a une regle de la mission : sans statut = rouge ; preuve inexistante
 * ou en echec = reclassee ; exclusion jamais comptee comme couverte ; deux pourcentages exacts.
 */
final class JugeTest extends TestCase
{
    /** @return list<array{id:string,categorie:string,type:string,libelle:string,reference:string}> */
    private function inventaire(): array
    {
        return [
            ['id' => 'api:route:documents', 'categorie' => 'api', 'type' => 'route', 'libelle' => 'route documents', 'reference' => 'src/paperless/urls.py:10'],
            ['id' => 'modele:classe:Document', 'categorie' => 'modele', 'type' => 'classe', 'libelle' => 'classe Document', 'reference' => 'src/documents/models.py:20'],
            ['id' => 'masse:fonction:delete', 'categorie' => 'masse', 'type' => 'fonction', 'libelle' => 'bulk_edit.delete', 'reference' => 'src/documents/bulk_edit.py:30'],
            ['id' => 'commande:documents:document_index', 'categorie' => 'commande', 'type' => 'commande', 'libelle' => 'document_index', 'reference' => 'src/documents/management/commands/document_index.py:1'],
        ];
    }

    /** @return array{routes:list<string>,ecrans:list<string>,services:list<string>} */
    private function connu(): array
    {
        return [
            'routes' => ['GET /bibliotheque'],
            'ecrans' => ['templates/ecrans/bibliotheque.twig'],
            'services' => ['Ged\\Socle\\ModificationMasse'],
        ];
    }

    public function testInventaireSansStatutEstRouge(): void
    {
        $correspondance = [
            'api:route:documents' => ['statut' => 'couvert', 'gedv2' => ['type' => 'route', 'methode' => 'GET', 'chemin' => '/bibliotheque'], 'preuve' => ['type' => 'phpunit', 'cible' => 'Ged\\Tests\\Ui\\EcranTest::testListe']],
            'modele:classe:Document' => ['statut' => 'absent'],
            'masse:fonction:delete' => ['statut' => 'absent'],
            // commande:documents:document_index volontairement sans statut.
        ];

        $rapport = (new Juge())->analyser($this->inventaire(), $correspondance, ['Ged\\Tests\\Ui\\EcranTest::testListe'], [], $this->connu());

        self::assertNotEmpty($rapport['erreurs'], 'un element sans statut doit rendre le juge rouge');
        self::assertStringContainsString('commande:documents:document_index', implode("\n", $rapport['erreurs']));
    }

    public function testCorrespondanceCitantUnIdInconnuEstRouge(): void
    {
        $correspondance = [
            'api:route:documents' => ['statut' => 'absent'],
            'modele:classe:Document' => ['statut' => 'absent'],
            'masse:fonction:delete' => ['statut' => 'absent'],
            'commande:documents:document_index' => ['statut' => 'absent'],
            'api:route:inexistant' => ['statut' => 'absent'],
        ];

        $rapport = (new Juge())->analyser($this->inventaire(), $correspondance, [], [], $this->connu());

        self::assertNotEmpty($rapport['erreurs']);
        self::assertStringContainsString('api:route:inexistant', implode("\n", $rapport['erreurs']));
    }

    public function testPreuveInexistanteReclasseNonProuve(): void
    {
        $correspondance = [
            'api:route:documents' => ['statut' => 'couvert', 'gedv2' => ['type' => 'route', 'methode' => 'GET', 'chemin' => '/bibliotheque'], 'preuve' => ['type' => 'phpunit', 'cible' => 'Ged\\Tests\\Fantome::testRien']],
            'modele:classe:Document' => ['statut' => 'absent'],
            'masse:fonction:delete' => ['statut' => 'absent'],
            'commande:documents:document_index' => ['statut' => 'absent'],
        ];

        $rapport = (new Juge())->analyser($this->inventaire(), $correspondance, ['Ged\\Tests\\Ui\\EcranTest::testListe'], [], $this->connu());

        self::assertSame(0, $rapport['global']['couverts'], 'une preuve inexistante ne couvre pas');
        self::assertSame(1, $rapport['global']['non_prouves']);
        $ecart = $this->ecart($rapport, 'api:route:documents');
        self::assertSame('non-prouve', $ecart['statut']);
    }

    public function testPreuveEnEchecReclasseNonProuve(): void
    {
        $correspondance = [
            'api:route:documents' => ['statut' => 'couvert', 'gedv2' => ['type' => 'route', 'methode' => 'GET', 'chemin' => '/bibliotheque'], 'preuve' => ['type' => 'phpunit', 'cible' => 'Ged\\Tests\\Ui\\EcranTest::testListe']],
            'modele:classe:Document' => ['statut' => 'absent'],
            'masse:fonction:delete' => ['statut' => 'absent'],
            'commande:documents:document_index' => ['statut' => 'absent'],
        ];

        $rapport = (new Juge())->analyser(
            $this->inventaire(),
            $correspondance,
            [], // aucune preuve reussie au dernier rejeu
            ['Ged\\Tests\\Ui\\EcranTest::testListe'], // la preuve citee a echoue
            $this->connu(),
        );

        $ecart = $this->ecart($rapport, 'api:route:documents');
        self::assertSame('non-prouve', $ecart['statut']);
        self::assertStringContainsString('echoue', (string) $ecart['raison']);
    }

    public function testEquivalentGedv2IntrouvableReclasseNonProuve(): void
    {
        $correspondance = [
            'api:route:documents' => ['statut' => 'couvert', 'gedv2' => ['type' => 'route', 'methode' => 'POST', 'chemin' => '/nulle-part'], 'preuve' => ['type' => 'phpunit', 'cible' => 'Ged\\Tests\\Ui\\EcranTest::testListe']],
            'modele:classe:Document' => ['statut' => 'absent'],
            'masse:fonction:delete' => ['statut' => 'absent'],
            'commande:documents:document_index' => ['statut' => 'absent'],
        ];

        $rapport = (new Juge())->analyser($this->inventaire(), $correspondance, ['Ged\\Tests\\Ui\\EcranTest::testListe'], [], $this->connu());

        $ecart = $this->ecart($rapport, 'api:route:documents');
        self::assertSame('non-prouve', $ecart['statut']);
        self::assertStringContainsString('GEDv2', (string) $ecart['raison']);
    }

    public function testExclusionJamaisCompteeCommeCouverteEtPourcentagesExacts(): void
    {
        // 4 elements : 2 couverts prouves, 1 exclu, 1 absent.
        $correspondance = [
            'api:route:documents' => ['statut' => 'couvert', 'gedv2' => ['type' => 'route', 'methode' => 'GET', 'chemin' => '/bibliotheque'], 'preuve' => ['type' => 'phpunit', 'cible' => 'Ged\\Tests\\Ui\\EcranTest::testListe']],
            'modele:classe:Document' => ['statut' => 'couvert-autrement', 'gedv2' => ['type' => 'service', 'classe' => 'Ged\\Socle\\ModificationMasse'], 'preuve' => ['type' => 'playwright', 'cible' => 'bibliotheque.spec.ts > modifie en masse']],
            'masse:fonction:delete' => ['statut' => 'exclu', 'exclusion' => 'infrastructure-django'],
            'commande:documents:document_index' => ['statut' => 'absent', 'lot' => 'V2-XX'],
        ];

        $rapport = (new Juge())->analyser(
            $this->inventaire(),
            $correspondance,
            ['Ged\\Tests\\Ui\\EcranTest::testListe', 'bibliotheque.spec.ts > modifie en masse'],
            [],
            $this->connu(),
        );

        self::assertSame(2, $rapport['global']['couverts']);
        self::assertSame(1, $rapport['global']['exclus']);
        self::assertSame(1, $rapport['global']['absents']);
        self::assertSame(50.0, $rapport['global']['strict_pct'], '2 couverts / 4 = 50 %');
        self::assertSame(66.67, $rapport['global']['hors_exclusions_pct'], '2 / 3 = 66.67 %');
        self::assertCount(1, $rapport['exclusions']);
        self::assertSame('exclu', $rapport['exclusions'][0]['statut']);
    }

    public function testExclusionSansRaisonEstUneErreur(): void
    {
        $correspondance = [
            'api:route:documents' => ['statut' => 'exclu', 'exclusion' => ''],
            'modele:classe:Document' => ['statut' => 'absent'],
            'masse:fonction:delete' => ['statut' => 'absent'],
            'commande:documents:document_index' => ['statut' => 'absent'],
        ];

        $rapport = (new Juge())->analyser($this->inventaire(), $correspondance, [], [], $this->connu());

        self::assertNotEmpty($rapport['erreurs']);
        self::assertStringContainsString('exclusion', implode("\n", $rapport['erreurs']));
    }

    public function testExclusionHorsListeFermeeEstRefuseeEtCompteeEcart(): void
    {
        $correspondance = [
            'api:route:documents' => ['statut' => 'exclu', 'exclusion' => 'raison-inventee'],
            'modele:classe:Document' => ['statut' => 'absent'],
            'masse:fonction:delete' => ['statut' => 'absent'],
            'commande:documents:document_index' => ['statut' => 'absent'],
        ];

        $rapport = (new Juge())->analyser($this->inventaire(), $correspondance, [], [], $this->connu());

        self::assertNotEmpty($rapport['erreurs']);
        self::assertStringContainsString('liste fermee', implode("\n", $rapport['erreurs']));
        $ecart = $this->ecart($rapport, 'api:route:documents');
        self::assertSame('absent', $ecart['statut'], 'une exclusion hors liste fermee compte comme un ecart');
    }

    /** @param array<string,mixed> $rapport @return array<string,mixed> */
    private function ecart(array $rapport, string $id): array
    {
        foreach ($rapport['ecarts'] as $ecart) {
            if ($ecart['id'] === $id) {
                return $ecart;
            }
        }
        self::fail('ecart introuvable pour ' . $id);
    }
}
