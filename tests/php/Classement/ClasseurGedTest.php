<?php

declare(strict_types=1);

namespace Ged\Tests\Classement;

use Ged\Classement\ClientIa;
use Ged\Classement\ReponseIa;
use PHPUnit\Framework\TestCase;

/**
 * Le classeur : confiance ≥ seuil ET preuve (indice du texte ou habitude) pour valider seul ;
 * l'IA propose, elle ne force jamais ; « Autre » n'est jamais validé seul ; l'émetteur non lu reste vide.
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

    public function testUneFactureEstValideeParLaRegleSansIA(): void
    {
        $texte = self::ENTETE . "FACTURE N° PS-2026-101\nDate : 05.01.2026\nTotal TTC CHF 700.38\n";
        $analyse = ['origine' => ['cles' => ['dates' => ['2026-01-05']]], 'facture' => ['amount' => 700.38]];

        $r = $this->classeur()->proposer($texte, $analyse, 'courrier (p. 1).pdf');

        self::assertSame('Facture', $r['type']);
        self::assertSame('Papeterie des Sapins Fictive SA', $r['emetteur']);
        self::assertSame('regle', $r['source']);
        self::assertSame('facture', $r['preuve']);
        self::assertTrue($r['valide']);
        self::assertNull($r['raison']);
        self::assertSame('2026-01-05', $r['date']);
        self::assertSame(700.38, $r['montant']);
        self::assertSame(self::CLES_CONTRAT, array_keys($r));
    }

    public function testUneIACorroboreePorteLaSource(): void
    {
        $texte = self::ENTETE . "FACTURE N° PS-2026-101\nTotal TTC CHF 700.38\n";
        $ia = new ClientIaSimule(new ReponseIa('Facture', 'Papeterie des Sapins Fictive SA', 'Facture PS-2026-101',
            '2026-01-05', 700.38, [], 0.95, 'simule'));

        $r = $this->classeur($ia)->proposer($texte, [], 'facture.pdf');

        self::assertSame('Facture', $r['type']);
        self::assertSame('ia', $r['source']);
        self::assertTrue($r['valide']);
        self::assertGreaterThanOrEqual(0.95, $r['confiance']);
    }

    public function testUneIASansIndiceDansLeTexteNEstPasValideeSeule(): void
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
        $r = $this->classeur()->proposer("FACTURE N° 12\nDate : 05.01.2026\nTotal TTC CHF 120.00\n", [], 'f.pdf');

        self::assertSame('Facture', $r['type']);
        self::assertTrue($r['valide']);
        self::assertNull($r['emetteur'], 'pas d’émetteur inventé');
    }

    public function testDocumentRhSansProprietaireNEstPasValide(): void
    {
        $texte = self::ENTETE . "Concerne : Attestation de travail - collaborateur fictif Jean Exemple\n";

        $r = $this->classeur()->proposer($texte, [], 'rh.pdf');

        self::assertSame('Document RH', $r['type']);
        self::assertFalse($r['valide']);
        self::assertStringContainsString('propriétaire', (string) $r['raison']);
    }

    public function testDocumentRhAvecProprietaireValideSeul(): void
    {
        $texte = "Commune de Modeleville\nHotel de Ville\n2999 Modeleville\nKarbonic Banc de test\n"
            . "Concerne : Attestation de travail - collaborateur fictif Jean Exemple\n";

        putenv('GED_ORGANISATION=Karbonic Banc de test');
        try {
            $r = $this->classeur()->proposer($texte, [], 'attestation.pdf');
        } finally {
            putenv('GED_ORGANISATION');
        }

        self::assertSame('Document RH', $r['type']);
        self::assertTrue($r['valide']);
        self::assertSame('regle', $r['source']);
    }

    public function testUneIAQuiEchoueNeBloquePasLaRegle(): void
    {
        $texte = self::ENTETE . "FACTURE N° PS-2026-101\nTotal TTC CHF 700.38\n";
        $ia = new ClientIaSimule(null, true);

        $r = $this->classeur($ia)->proposer($texte, [], 'facture.pdf');

        self::assertSame('Facture', $r['type']);
        self::assertSame('regle', $r['source'], 'l’IA en panne se replie sur la règle');
        self::assertTrue($r['valide']);
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
