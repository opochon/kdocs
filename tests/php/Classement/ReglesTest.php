<?php

declare(strict_types=1);

namespace Ged\Tests\Classement;

use Ged\Classement\Regles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les règles lisent le texte, elles ne devinent pas : l'émetteur est le premier titre du document,
 * le type ne se valide que sur un indice fort, et « Autre » n'est jamais un classement.
 */
final class ReglesTest extends TestCase
{
    public function testNormaliserEnleveAccentsEtPonctuation(): void
    {
        self::assertSame('note de credit', Regles::normaliser('  NOTE DE CRÉDIT '));
        self::assertSame('recu', Regles::normaliser('Reçu'));
    }

    /** @return array<string, array{string, ?string}> */
    public static function emetteurs(): array
    {
        return [
            'gras avec adresse collee' => [
                "**Banque Fictive du Jura** Grand-Rue 1 2800 Delemont\nKarbonic Banc de test\n",
                'Banque Fictive du Jura',
            ],
            'lettre scannee (premiere ligne)' => [
                "Gerance Immobiliere Modele SA\nPlace du Test 3\n2400 Le Locle\nCHE-100.000.003 TVA\n",
                'Gerance Immobiliere Modele SA',
            ],
            'adresse avant le titre' => [
                "Hotel de Ville 2999 Modeleville\n\n# **Commune de Modeleville**\n\nKarbonic Banc de test\n",
                'Commune de Modeleville',
            ],
            'gras simple' => [
                "**Papeterie des Sapins Fictive SA**\nRue du Marche 12 2300 La Chaux-de-Fonds\n",
                'Papeterie des Sapins Fictive SA',
            ],
        ];
    }

    #[DataProvider('emetteurs')]
    public function testLireEmetteurDansLEntete(string $texte, string $attendu): void
    {
        self::assertSame($attendu, Regles::lireEmetteur($texte));
    }

    public function testSansEnTeteLisibleLEmetteurResteVide(): void
    {
        self::assertNull(Regles::lireEmetteur("FACTURE N° 12\nDate : 05.01.2026\nTotal TTC CHF 120.00\n"));
    }

    public function testLeProprietaireDeLaGedNEstJamaisEmetteur(): void
    {
        $texte = "**Karbonic Banc de test**\n**Atelier Numerique Exemple Sarl**\nAvenue Leopold-Robert 99\n";

        self::assertSame('Atelier Numerique Exemple Sarl', Regles::lireEmetteur($texte, 'Karbonic Banc de test'));
    }

    /** @return array<string, array{string, ?string, string}> */
    public static function types(): array
    {
        return [
            'facture' => ["FACTURE N° PS-2026-101\nDate : 05.01.2026\nTotal TTC CHF 700.38\n", 'Facture', 'facture'],
            'note de credit' => ["NOTE DE CREDIT N° AN-5001-NC\nTotal TTC CHF -437.81\n", 'Note de crédit', 'note de credit'],
            'recu' => ["RECU\nRecu la somme de CHF 74.55 en especes. Ticket R-2001.\n", 'Reçu', 'recu'],
            'contrat' => ["Concerne : Contrat de bail commercial - locaux Chemin de l'Essai 1\n", 'Contrat', 'contrat'],
            'police est un contrat' => ["Concerne : Police responsabilite civile entreprise n° RC-000123\n", 'Contrat', 'police'],
            'decompte' => ["DÉCOMPTE de prestations 2026\n", 'Décompte', 'decompte'],
            'releve' => ["RELEVÉ de compte professionnel\n", 'Relevé', 'releve'],
            'attestation' => ["ATTESTATION fiscale annuelle\n", 'Attestation', 'attestation'],
            'police assurance' => ["POLICE D'ASSURANCE responsabilité civile\n", 'Police d’assurance', 'police d assurance'],
            'avis impot' => ["AVIS D'IMPÔT communal 2026\n", 'Avis d’impôt', 'avis d impot'],
            'decision' => ["DÉCISION administrative sur votre recours\n", 'Décision', 'decision'],
            'document rh' => ["Concerne : Attestation de travail - collaborateur fictif Jean Exemple\n", 'Document RH', 'attestation de travail'],
            'releve dans un courrier' => ["Concerne : Releve de compte courant - decembre 2025\n", 'Relevé', 'releve'],
            'aucun indice' => ["Du texte sans marqueur utile.\n", null, ''],
        ];
    }

    #[DataProvider('types')]
    public function testTypeEtPreuve(string $texte, ?string $typeAttendu, string $preuveAttendue): void
    {
        $r = Regles::typeEtPreuve($texte);

        self::assertSame($typeAttendu, $r['type'] ?? null);
        self::assertSame($preuveAttendue, $r['preuve'] ?? '');
    }

    public function testUnContratDeTravailResteUnDocumentRh(): void
    {
        $r = Regles::typeEtPreuve("Concerne : Contrat de travail du collaborateur fictif\n");

        self::assertNotNull($r);
        self::assertSame('Document RH', $r['type'], 'la règle RH passe avant la règle Contrat');
    }

    /** @return array<string, array{string, ?string}> */
    public static function datesEnTete(): array
    {
        return [
            'lieu, le JJ.MM.AAAA' => ["Hotel de Ville\n\nModeleville, le 22.01.2026\n\nConcerne : Attestation\n", '2026-01-22'],
            'date de facture' => ["FACTURE N° PS-2026-101\nDate : 05.01.2026 Echeance : 30 jours\n", '2026-01-05'],
            'lieu le mois francais' => ["Villemodele, le 5 novembre 2010\n", '2010-11-05'],
            'lieu le mois allemand' => ["Musterstadt, den 7 März 2011\n", '2011-03-07'],
            'lieu il mese italiano' => ["Città modello, il 9 ottobre 2012\n", '2012-10-09'],
            'une date du corps seulement' => ["Nous attestons un emploi depuis le 1er janvier 2024.\n", null],
            'aucune date' => ["Du texte sans date.\n", null],
        ];
    }

    #[DataProvider('datesEnTete')]
    public function testDateEnTete(string $texte, ?string $attendue): void
    {
        self::assertSame($attendue, Regles::dateEnTete($texte));
    }

    public function testDateEnTeteNeConfondPasUnNumeroIde(): void
    {
        self::assertNull(Regles::dateEnTete("**Atelier Numerique Exemple Sarl**\nCHE-100.000.002 TVA\n"));
    }

    public function testDateEnTeteIgnoreNaissanceEtPeriodeEtGardeEmission(): void
    {
        $texte = "Post CHAG\nDate de naissance : 14.09.1976\nPériode de prestation : 01.01.2010 au 31.12.2010\n"
            . "Lausanne, le 14.09.2026\n";

        self::assertSame('2026-09-14', Regles::dateEnTete($texte));
        self::assertFalse(Regles::datePlausible('1976-09-14'));
        self::assertFalse(Regles::datePlausible('2080-01-01'));
    }

    public function testTitreVientDeLaPieceJamaisDuLot(): void
    {
        self::assertSame(
            'Attestation de travail - collaborateur fictif Jean Exemple',
            Regles::titrePiece("Concerne : Attestation de travail - collaborateur fictif Jean Exemple\n", 'Document RH', 'Commune de Modeleville', null),
        );
        self::assertSame(
            'PS-2026-101',
            Regles::titrePiece("FACTURE N° PS-2026-101\nTotal TTC CHF 700.38\n", 'Facture', 'Papeterie des Sapins Fictive SA', null),
        );
        self::assertSame(
            'Reçu Garage du Banc d’Essai Sarl 2026-02-20',
            Regles::titrePiece("RECU\nRecu la somme de CHF 74.55 en especes.\n", 'Reçu', 'Garage du Banc d’Essai Sarl', '2026-02-20'),
        );
    }

    public function testLeProprietaireDoitFigurerDansLaPiecePourUnDocumentRh(): void
    {
        $texte = "**Commune de Modeleville**\nConcerne : Attestation de travail - Jean Exemple\n";
        self::assertFalse(Regles::proprietaireDansTexte($texte, ''));
        self::assertFalse(Regles::proprietaireDansTexte($texte, 'Karbonic Banc de test'));
        self::assertTrue(Regles::proprietaireDansTexte($texte, 'Commune de Modeleville'));
    }

    public function testUnTypeJamaisValideSeulEStReconnu(): void
    {
        self::assertTrue(Regles::estAutre('Autre'));
        self::assertTrue(Regles::estAutre('autre'));
        self::assertTrue(Regles::estAutre('DIVERS'));
        self::assertFalse(Regles::estAutre('Facture'));
        self::assertFalse(Regles::estAutre(null));
    }

    public function testLesReglesNeProposentJamaisAutre(): void
    {
        foreach (self::types() as [$texte, $type, $_preuve]) {
            self::assertNotSame('Autre', $type);
            $r = Regles::typeEtPreuve($texte);
            self::assertNotSame('Autre', $r['type'] ?? null);
        }
    }

    public function testNomSeLitDansLeTexte(): void
    {
        $texte = Regles::normaliser("Papeterie des Sapins Fictive SA\nRoute de l'Entrepot\n");
        self::assertTrue(Regles::nomSeLit($texte, 'Papeterie des Sapins Fictive SA'));
        self::assertFalse(Regles::nomSeLit($texte, 'Garage du Banc d Essai Sarl'));
        self::assertFalse(Regles::nomSeLit($texte, ''));
    }

    public function testMemeTypeIgnoreLesVariantes(): void
    {
        self::assertTrue(Regles::memeType('Reçu', 'recu'));
        self::assertTrue(Regles::memeType('Note de crédit', 'NOTE DE CREDIT'));
        self::assertFalse(Regles::memeType('Facture', 'Contrat'));
    }

    public function testLesSousCasDeTypesNeSontPasUnDesaccord(): void
    {
        self::assertTrue(Regles::typesCompatibles('Décompte', 'Facture'));
        self::assertTrue(Regles::typesCompatibles('Police d’assurance', 'Contrat'));
        self::assertTrue(Regles::typesCompatibles('Avis d’impôt', 'Facture'));
        self::assertFalse(Regles::typesCompatibles('Relevé', 'Facture'));
    }

    public function testLesIndicesNouveauxCouvrentLesTroisLangues(): void
    {
        self::assertSame('Avis d’impôt', Regles::typeEtPreuve('STEUERVERANLAGUNG 2026')['type']);
        self::assertSame('Police d’assurance', Regles::typeEtPreuve('VERSICHERUNGSSCHEIN')['type']);
        self::assertSame('Décompte', Regles::typeEtPreuve('CONTEGGIO PRESTAZIONI')['type']);
        self::assertSame('Relevé', Regles::typeEtPreuve('KONTOAUSZUG')['type']);
        self::assertSame('Attestation', Regles::typeEtPreuve('CERTIFICAZIONE FISCALE')['type']);
        self::assertSame('Décision', Regles::typeEtPreuve('PROVVEDIMENTO amministrativo')['type']);
    }

    public function testRapprochementEmetteurIgnoreFormeJuridiqueEtAccepteInclusionSignificative(): void
    {
        self::assertTrue(Regles::emetteursCorrespondent('Assurances Boréales SA', 'ASSURANCES BOREALES AG'));
        self::assertTrue(Regles::emetteursCorrespondent('Banque Cantonale du Lac SA', 'Banque Cantonale du Lac'));
        self::assertFalse(Regles::emetteursCorrespondent('Banque', 'Banque'), 'un mot générique seul ne vaut pas rapprochement');
        self::assertFalse(Regles::emetteursCorrespondent('Service Relation Client', 'Service Client'));
    }

    #[DataProvider('emetteursOcrNonCredibles')]
    public function testUnePhraseOuUnLibelleNeDevientJamaisEmetteur(string $texte): void
    {
        self::assertNull(Regles::lireEmetteur($texte));
    }

    /** @return array<string, array{string}> */
    public static function emetteursOcrNonCredibles(): array
    {
        return [
            'phrase conditionnelle' => ["Si l'établissement utilise son droit contractuel, il vous préviendra.\n"],
            'phrase administrative' => ["La demanderesse ne reçoit plus aucune prestation de l'assurance concernée.\n"],
            'formule medecin' => ["Le cabinet médical vous adresse sa note d'honoraires.\n"],
            'libelle destinataire' => ["Nom du destinataire : Atelier Fictif Sarl\n"],
            'service generique' => ["Service clientèle\n"],
            'contact center de service' => ["Contact Center, Service Réclamations\n"],
            'fragment apres preposition' => ["De votre contrat, nous vous informons.\n"],
        ];
    }

    public function testUnPrefixeOcrEstRetireDevantUneRaisonSociale(): void
    {
        self::assertSame('Assurances Boréales SA', Regles::lireEmetteur("xx Assurances Boréales SA\nDate : 05.01.2026"));
    }
}
