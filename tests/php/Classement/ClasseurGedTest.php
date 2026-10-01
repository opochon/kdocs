<?php

declare(strict_types=1);

namespace Ged\Tests\Classement;

use Ged\Classement\ClientIa;
use Ged\Classement\ClientIaNul;
use Ged\Classement\Habitudes;
use Ged\Classement\ReponseIa;
use PHPUnit\Framework\TestCase;

/**
 * Le classeur ne valide seul que sur une PREUVE corroborée (règle de GEDv1, `typeCorrobore`) :
 * IA ≥ seuil ET indice du même type dans le texte ou habitude. L'émetteur et la date doivent aussi
 * être corroborés : un mot-clé seul, une IA qui diverge, se tait ou tombe en panne reste à contrôler.
 * « Autre » n'est jamais validé seul ; l'émetteur non lu reste vide.
 */
final class ClasseurGedTest extends TestCase
{
    use SupportClassement;

    protected function setUp(): void
    {
        $this->setUpClassement();
    }

    private const ENTETE = "Papeterie des Sapins Fictive SA\nRue du Marche 12\n2300 La Chaux-de-Fonds\n";

    private const CLES_CONTRAT = ['titre', 'type', 'emetteur', 'date', 'montant', 'etiquettes', 'confiance',
        'source', 'preuve', 'valide', 'raison'];

    // ---- Le mot-clé seul ne valide pas (jugement V2-03, règle typeCorrobore) -----------------

    public function testUnMotCleSeulSansIANEstPasValideSeul(): void
    {
        $texte = self::ENTETE . "FACTURE N° PS-2026-101\nDate : 05.01.2026\nTotal TTC CHF 700.38\n";
        $analyse = ['origine' => ['cles' => ['dates' => ['2026-01-05']]], 'facture' => ['amount' => 700.38]];

        $r = $this->classeur()->proposer($texte, $analyse, 'courrier (p. 1).pdf');

        self::assertSame('Facture', $r['type'], 'le mot-clé reste une suggestion');
        self::assertSame('Papeterie des Sapins Fictive SA', $r['emetteur']);
        self::assertSame('regle', $r['source']);
        self::assertSame('facture', $r['preuve']);
        self::assertFalse($r['valide'], 'sans IA corroborante, un mot-clé seul ne range pas tout seul');
        self::assertStringContainsString('IA', (string) $r['raison']);
        self::assertSame('2026-01-05', $r['date']);
        self::assertSame(700.38, $r['montant']);
        self::assertSame(self::CLES_CONTRAT, array_keys($r));
    }

    public function testLaDateEtLeTitreViennentDeLaPiecePasDuCorpsNiDuLot(): void
    {
        $texte = "Hotel de Ville 2999 Modeleville\n\n# **Commune de Modeleville**\n\n"
            . "Karbonic Banc de test Chemin de l'Essai 1 2300 La Chaux-de-Fonds\n\n"
            . "Modeleville, le 22.01.2026\n\n"
            . "## **Concerne : Attestation de travail - collaborateur fictif Jean Exemple**\n\n"
            . "Nous attestons que M. Jean Exemple est employe depuis le 1er janvier 2024.\n";
        $analyse = ['origine' => ['cles' => ['dates' => ['2024-01-01', '2026-01-22']]]];

        $r = $this->classeur()->proposer($texte, $analyse, 'courrier-du-matin-100p (p. 21).pdf');

        self::assertSame('Document RH', $r['type']);
        self::assertSame('2026-01-22', $r['date'], 'la date de l’en-tête prime sur la date citée dans le corps');
        self::assertSame('Attestation de travail - collaborateur fictif Jean Exemple', $r['titre']);
        self::assertStringNotContainsString('courrier-du-matin', (string) $r['titre'], 'jamais le nom du lot');
    }

    public function testUneIaQuiCorroboreLeTexteValideSeul(): void
    {
        $texte = self::ENTETE . "FACTURE N° PS-2026-101\nDate : 05.01.2026\nTotal TTC CHF 700.38\n";
        $ia = new ClientIaSimule(new ReponseIa('Facture', 'Papeterie des Sapins Fictive SA', 'Facture PS-2026-101',
            '2026-01-05', 700.38, [], 0.95, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'facture.pdf');

        self::assertSame('Facture', $r['type']);
        self::assertSame('ia', $r['source'], 'l’IA qui corrobore l’indice porte la source');
        self::assertStringContainsString('facture', (string) $r['preuve']);
        self::assertTrue($r['valide']);
        self::assertNull($r['raison']);
        self::assertGreaterThanOrEqual(0.95, $r['confiance']);
    }

    public function testUneIaEnDesaccordAvecLeTexteNEstPasValideeSeule(): void
    {
        $texte = self::ENTETE . "FACTURE N° PS-2026-101\nTotal TTC CHF 700.38\n";
        $ia = new ClientIaSimule(new ReponseIa('Contrat', 'Papeterie des Sapins Fictive SA', null,
            null, null, [], 0.95, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'facture.pdf');

        self::assertFalse($r['valide'], 'IA et texte ne disent pas le même type');
        self::assertSame('Facture', $r['type'], 'le texte est montré à l’humain');
        self::assertStringContainsString('divergent', (string) $r['raison']);
        self::assertStringContainsString('Contrat', (string) $r['raison']);
        self::assertStringContainsString('Facture', (string) $r['raison']);
    }

    public function testUnSousCasIaEtTexteSeValideSansFausseDivergence(): void
    {
        $texte = self::ENTETE . "FACTURE — DÉCOMPTE de prestations\nDate : 05.01.2026\n";
        $ia = new ClientIaSimule(new ReponseIa('Décompte', 'Papeterie des Sapins Fictive SA', null,
            '2026-01-05', null, [], 0.95, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'decompte.pdf');

        self::assertSame('Décompte', $r['type']);
        self::assertTrue($r['valide']);
    }

    public function testLaFamilleCompatibleSansIndiceDuSousTypeNeValidePasSeule(): void
    {
        $texte = self::ENTETE . "FACTURE — avis fiscal annuel\nDate : 05.01.2026\n";
        $ia = new ClientIaSimule(new ReponseIa('Avis d’impôt', 'Papeterie des Sapins Fictive SA', null,
            '2026-01-05', null, [], 0.95, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'avis.pdf');

        self::assertSame('Avis d’impôt', $r['type']);
        self::assertFalse($r['valide']);
        self::assertStringContainsString('sous-type', (string) $r['raison']);
    }

    public function testUneFamilleCompatibleSansIndiceDuSousTypeResteAControler(): void
    {
        $texte = "Banque Exemple SA\nCONTRAT de crédit hypothécaire\nDate : 05.01.2026\n";
        $ia = new ClientIaSimule(new ReponseIa('Police d’assurance', 'Banque Exemple SA', null,
            '2026-01-05', null, [], 0.95, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'credit.pdf');

        self::assertSame('Police d’assurance', $r['type'], 'le sous-type IA reste proposé au contrôleur');
        self::assertFalse($r['valide']);
        self::assertStringContainsString('sous-type', (string) $r['raison']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('datesFactureQualifiees')]
    public function testUneDateDeFactureQualifieeEstLue(string $libelle, string $date): void
    {
        $texte = "Énergies Modèle SA\nFACTURE N° EN-26\n$libelle $date\n";

        self::assertSame('2026-09-14', \Ged\Classement\Regles::dateEnTete($texte));
    }

    public static function datesFactureQualifiees(): array
    {
        return [
            'fr' => ['Date de facture :', '14.09.2026'],
            'de' => ['Rechnungsdatum :', '14.09.2026'],
            'it' => ['Data fattura :', '14.09.2026'],
        ];
    }

    public function testUnEmetteurIaInclusDansLeTexteEstCorroboreMalgreLaFormeJuridique(): void
    {
        $texte = "Assurances Boréales SA\nFACTURE F-2026-01\nDate : 05.01.2026\n";
        $ia = new ClientIaSimule(new ReponseIa('Facture', 'ASSURANCES BOREALES AG', null,
            '2026-01-05', null, [], 0.95, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'facture.pdf');

        self::assertTrue($r['valide']);
        self::assertSame('Assurances Boréales SA', $r['emetteur'], 'une forme juridique unique évite deux dossiers');
    }

    public function testUneFactureEnergieAvecDateDecaleeEstValideeSeule(): void
    {
        $texte = "FAMILLE SYNTHESE\nRue des Tests 8\n1200 Modeleville\n\nALPINA ENERGIE AG\n"
            . "FACTURE energie\nN° EN-2025-07\nDate de facture\n04.07.2025\nMontant CHF 84.20\n";
        $ia = new ClientIaSimule(new ReponseIa('Facture', 'Alpina Energie SA', null,
            '2025-07-04', 84.20, [], 0.97, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'energie.pdf');

        self::assertSame('ALPINA ENERGIE SA', $r['emetteur']);
        self::assertSame('2025-07-04', $r['date']);
        self::assertTrue($r['valide']);
    }

    public function testUneIaPeuSureNeValidePasSeule(): void
    {
        $texte = self::ENTETE . "FACTURE N° PS-2026-101\nTotal TTC CHF 700.38\n";
        $ia = new ClientIaSimule(new ReponseIa('Facture', null, null, null, null, [], 0.4, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'facture.pdf');

        self::assertFalse($r['valide'], 'une IA d’accord mais peu sûre ne suffit pas');
        self::assertStringContainsString('confiance', (string) $r['raison']);
    }

    public function testDeuxIndicesContradictoiresSansIaNEstPasValideSeule(): void
    {
        // « facture » et « contrat » dans la même pièce : la règle penche pour Facture (priorité), mais
        // sans IA qui tranche, la divergence interne interdit le rangement automatique.
        $texte = self::ENTETE
            . "FACTURE N° PS-2026-101\nConcerne : contrat de bail commercial\nTotal TTC CHF 700.38\n";

        $r = $this->classeur()->proposer($texte, [], 'piece.pdf');

        self::assertSame('Facture', $r['type']);
        self::assertFalse($r['valide'], 'deux indices contradictoires : à contrôler sans IA d’accord');
        self::assertNotNull($r['raison']);
    }

    public function testUneIaSansIndiceDansLeTexteNEstPasValideeSeule(): void
    {
        $texte = self::ENTETE . "Aucun mot qui trahisse le type.\n";
        $ia = new ClientIaSimule(new ReponseIa('Contrat', null, null, null, null, [], 0.95, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'piece.pdf');

        self::assertSame('Contrat', $r['type'], 'la suggestion est montrée à l’humain');
        self::assertSame('ia', $r['source']);
        self::assertFalse($r['valide'], 'aucun indice du type dans le texte');
        self::assertNotNull($r['raison']);
    }

    public function testAutreNEstJamaisValideSeul(): void
    {
        $texte = self::ENTETE . "Un mémo interne ordinaire, sans indication de nature.\n";
        $ia = new ClientIaSimule(new ReponseIa('Autre', null, null, null, null, [], 0.99, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'piece.pdf');

        self::assertFalse($r['valide']);
        self::assertNotNull($r['raison']);
    }

    public function testSansIndiceDuToutLeRepliCourrierEstProposeSansValidation(): void
    {
        $r = $this->classeur()->proposer("Du texte sans en-tête ni marqueur.\n", [], 'piece.pdf');

        self::assertSame('Courrier', $r['type']);
        self::assertFalse($r['valide']);
        self::assertSame('regle', $r['source']);
    }

    public function testEmetteurNonLuResteVide(): void
    {
        $texte = "FACTURE N° 12\nDate : 05.01.2026\nTotal TTC CHF 120.00\n";
        $ia = new ClientIaSimule(new ReponseIa('Facture', null, null, null, null, [], 0.95, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'f.pdf');

        self::assertSame('Facture', $r['type']);
        self::assertFalse($r['valide'], 'type juste sans émetteur crédible : à contrôler');
        self::assertNull($r['emetteur'], 'pas d’émetteur inventé');
        self::assertStringContainsString('émetteur', (string) $r['raison']);
    }

    public function testDocumentRhSansProprietaireNEstPasValide(): void
    {
        $texte = self::ENTETE . "Concerne : Attestation de travail - collaborateur fictif Jean Exemple\n";
        $ia = new ClientIaSimule(new ReponseIa('Document RH', null, null, null, null, [], 0.95, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'rh.pdf');

        self::assertSame('Document RH', $r['type']);
        self::assertFalse($r['valide'], 'IA d’accord ne suffit pas : le propriétaire doit figurer dans la pièce');
        self::assertStringContainsString('propriétaire', (string) $r['raison']);
    }

    public function testDocumentRhAvecProprietaireValideAvecIa(): void
    {
        $texte = "Commune de Modeleville\nHotel de Ville\n2999 Modeleville\nKarbonic Banc de test\n"
            . "Date : 05.01.2026\nConcerne : Attestation de travail - collaborateur fictif Jean Exemple\n";
        $ia = new ClientIaSimule(new ReponseIa('Document RH', 'Commune de Modeleville', null,
            '2026-01-05', null, [], 0.95, 'simule'));

        putenv('GED_ORGANISATION=Karbonic Banc de test');
        try {
            $r = $this->classeur($ia)->proposer($texte, [], 'attestation.pdf');
        } finally {
            putenv('GED_ORGANISATION');
        }

        self::assertSame('Document RH', $r['type']);
        self::assertTrue($r['valide']);
        self::assertSame('ia', $r['source']);
    }

    public function testUneIaEnPanneNEstPasValideeSeule(): void
    {
        $texte = self::ENTETE . "FACTURE N° PS-2026-101\nTotal TTC CHF 700.38\n";
        $ia = new ClientIaSimule(null, true);

        $r = $this->classeur($ia)->proposer($texte, [], 'facture.pdf');

        self::assertSame('Facture', $r['type'], 'l’IA en panne se replie sur la suggestion de la règle');
        self::assertSame('regle', $r['source']);
        self::assertFalse($r['valide'], 'une panne IA ne range jamais toute seule');
        self::assertStringContainsString('IA', (string) $r['raison']);
    }

    // ---- L'habitude vaut preuve à elle seule (jugement V2-03) --------------------------------

    public function testUneHabitudeCorroboreeParIaValideSeule(): void
    {
        $h = new Habitudes($this->pdo);
        foreach (['doc-1', 'doc-2', 'doc-3'] as $ref) {
            $h->retenirChoix('Papeterie des Sapins Fictive SA', Habitudes::CHAMP_TYPE, 'Contrat', $ref);
        }

        // 4e pièce du même émetteur : l'habitude et l'IA disent Contrat, le texte est ambigu et l'humain
        // a déjà enseigné cette série. Émetteur et date restent, eux aussi, corroborés.
        $texte = self::ENTETE . "Date : 05.01.2026\nConcerne : contrat-cadre annuel\n";
        $ia = new ClientIaSimule(new ReponseIa('Contrat', 'Papeterie des Sapins Fictive SA', null,
            '2026-01-05', null, [], 0.95, 'simule'));
        $r = $this->classeur($ia)->proposer($texte, [], 'contrat.pdf');

        self::assertSame('Contrat', $r['type']);
        self::assertSame('habitude', $r['source']);
        self::assertTrue($r['valide'], 'l’habitude corroborée par l’IA valide la 4e pièce');
    }

    public function testUnEmetteurApprisParHumainContourneSeulementLeFiltreOcrAutomatique(): void
    {
        $emetteur = 'Emetteur Oracle DMYPIQ'; // nom synthétique volontairement sans suffixe de société
        $h = new Habitudes($this->pdo);
        foreach (['ecran-1', 'ecran-2', 'ecran-3'] as $ref) {
            $h->retenirChoix($emetteur, Habitudes::CHAMP_TYPE, 'Facture', $ref);
        }

        $r = $this->classeur(new ClientIaSimule(new ReponseIa('Facture', $emetteur, null,
            '2026-01-05', null, [], 0.95, 'simule')))->proposer(
                $emetteur . "\nFACTURE FICTIVE-4\nDate : 05.01.2026\nConcerne : Facture oracle\n", [], 'piece-4.pdf');

        self::assertSame($emetteur, $r['emetteur']);
        self::assertSame('habitude', $r['source']);
        self::assertTrue($r['valide']);
        self::assertStringContainsString('habitude', (string) $r['preuve']);
    }

    public function testUnePieceSansTypeReplieVersCourrierAvecUnTitreMetier(): void
    {
        $r = $this->classeur(new ClientIaNul())->proposer(
            "Fondation Exemple\nObjet : Information annuelle aux membres\nDate : 18.09.2026\n", [], 'scan-sans-type.pdf');

        self::assertSame('Courrier', $r['type']);
        self::assertSame('Information annuelle aux membres', $r['titre']);
        self::assertFalse($r['valide']);
    }

    public function testUnTitreIaReduitAUneDateReprendLObjectDeLaPiece(): void
    {
        $r = $this->classeur(new ClientIaSimule(new ReponseIa('Courrier', 'Fondation Exemple', '26.09.2026',
            '2026-09-26', null, [], 0.98, 'simule')))->proposer(
                "FONDATION EXEMPLE\nObjet : Information annuelle aux membres\nDate : 26.09.2026\n", [], 'scan-titre-date.pdf');

        self::assertSame('Courrier', $r['type']);
        self::assertSame('Information annuelle aux membres', $r['titre']);
    }

    public function testUnDestinataireRecurrentNeDevientJamaisEmetteurMemeProposeParIa(): void
    {
        $destinataire = 'Atelier du Foyer Synthétique SA';
        foreach (['lot-a-01.pdf', 'lot-b-01.pdf'] as $fichier) {
            $this->classeur(new ClientIaNul())->proposer(
                "Monsieur\n$destinataire\nRue des Essais 14\n1000 Modeleville\n\nAssurances du Rivage SA\nRAPPEL\nDate : 12.09.2026\n",
                [], $fichier,
            );
        }

        $r = $this->classeur(new ClientIaSimule(new ReponseIa('Courrier', $destinataire, null,
            '2026-09-13', null, [], 0.98, 'simule')))->proposer(
                "$destinataire\nAssurances du Rivage SA\nRAPPEL\nDate : 13.09.2026\n", [], 'lot-c-01.pdf');

        self::assertSame('Assurances du Rivage SA', $r['emetteur']);
        self::assertNotSame($destinataire, $r['emetteur']);
        self::assertFalse($r['valide'], 'une IA qui nomme un destinataire connu ne corrobore jamais un émetteur');
    }
}

/** Client IA simulé : aucune requête réseau, réponse figée ou panne. */
final class ClientIaSimule implements ClientIa
{
    public function __construct(
        private readonly ?ReponseIa $reponse,
        private readonly bool $panne = false,
    ) {
    }

    public function classer(string $texte, string $nomFichier, array $typesConnus): ?ReponseIa
    {
        if ($this->panne) {
            throw new \RuntimeException('service IA injoignable');
        }

        return $this->reponse;
    }

    public function aExpire(): bool
    {
        return false;
    }

    public function disponible(): bool
    {
        return true;
    }

    public function modele(): string
    {
        return 'simule';
    }
}
