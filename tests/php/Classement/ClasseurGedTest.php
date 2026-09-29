<?php

declare(strict_types=1);

namespace Ged\Tests\Classement;

use Ged\Classement\ClientIa;
use Ged\Classement\Habitudes;
use Ged\Classement\ReponseIa;
use PHPUnit\Framework\TestCase;

/**
 * Le classeur ne valide seul que sur une PREUVE corroborée (règle de GEDv1, `typeCorrobore`) :
 * habitude de l'émetteur, OU (IA ≥ seuil ET indice du même type dans le texte). Un mot-clé seul
 * reste une suggestion ; l'IA qui diverge, se tait ou tombe en panne laisse la pièce à contrôler.
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
        $texte = self::ENTETE . "FACTURE N° PS-2026-101\nTotal TTC CHF 700.38\n";
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

    public function testSansIndiceDuToutAucunTypeNEstPropose(): void
    {
        $r = $this->classeur()->proposer("Du texte sans en-tête ni marqueur.\n", [], 'piece.pdf');

        self::assertNull($r['type']);
        self::assertFalse($r['valide']);
        self::assertSame('regle', $r['source']);
    }

    public function testEmetteurNonLuResteVide(): void
    {
        $texte = "FACTURE N° 12\nDate : 05.01.2026\nTotal TTC CHF 120.00\n";
        $ia = new ClientIaSimule(new ReponseIa('Facture', null, null, null, null, [], 0.95, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'f.pdf');

        self::assertSame('Facture', $r['type']);
        self::assertTrue($r['valide']);
        self::assertNull($r['emetteur'], 'pas d’émetteur inventé');
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
            . "Concerne : Attestation de travail - collaborateur fictif Jean Exemple\n";
        $ia = new ClientIaSimule(new ReponseIa('Document RH', 'Commune de Modeleville', null,
            null, null, [], 0.95, 'simule'));

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

    public function testUneHabitudeValideSeuleSansIa(): void
    {
        $h = new Habitudes($this->pdo);
        foreach (['doc-1', 'doc-2', 'doc-3'] as $ref) {
            $h->retenirChoix('Papeterie des Sapins Fictive SA', Habitudes::CHAMP_TYPE, 'Contrat', $ref);
        }

        // 4e pièce du même émetteur : le mot-clé dit Facture, l'habitude dit Contrat — l'habitude prime.
        $texte = self::ENTETE . "FACTURE N° PS-2026-101\nTotal TTC CHF 700.38\n";
        $r = $this->classeur()->proposer($texte, [], 'facture.pdf');

        self::assertSame('Contrat', $r['type']);
        self::assertSame('habitude', $r['source']);
        self::assertTrue($r['valide'], 'trois choix humains identiques valident la 4e pièce');
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
