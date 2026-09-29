<?php

declare(strict_types=1);

namespace Ged\Tests\Classement;

use Ged\Classement\ClientIa;
use Ged\Classement\Habitudes;
use Ged\Classement\ReponseIa;
use PHPUnit\Framework\TestCase;

/**
 * L'apprentissage léger : N choix humains identiques et consécutifs pour un même émetteur font une
 * habitude ; un choix contraire la casse ; le tri automatique n'enseigne JAMAIS.
 */
final class HabitudesTest extends TestCase
{
    use SupportClassement;

    private const EMETTEUR = "Garage du Banc d'Essai Sarl";

    protected function setUp(): void
    {
        $this->setUpClassement();
    }

    public function testDeuxChoixNeFontPasEncoreUneHabitude(): void
    {
        $h = new Habitudes($this->pdo);

        $h->retenirChoix(self::EMETTEUR, Habitudes::CHAMP_TYPE, 'Vehicule', 'doc-1');
        $h->retenirChoix(self::EMETTEUR, Habitudes::CHAMP_TYPE, 'Vehicule', 'doc-2');

        self::assertNull($h->habitude(self::EMETTEUR), 'il faut 3 choix identiques');
    }

    public function testTroisChoixIdentiquesFontUneHabitude(): void
    {
        $h = new Habitudes($this->pdo);
        foreach (['doc-1', 'doc-2', 'doc-3'] as $ref) {
            $h->retenirChoix(self::EMETTEUR, Habitudes::CHAMP_TYPE, 'Vehicule', $ref);
        }

        $habitude = $h->habitude(self::EMETTEUR);

        self::assertNotNull($habitude);
        self::assertSame('Vehicule', $habitude['valeur']);
        self::assertSame(3, $habitude['choix']);
        self::assertGreaterThanOrEqual(0.8, $habitude['confiance']);
    }

    public function testUnRevirementCasseLHabitude(): void
    {
        $h = new Habitudes($this->pdo);
        foreach (['doc-1', 'doc-2', 'doc-3'] as $ref) {
            $h->retenirChoix(self::EMETTEUR, Habitudes::CHAMP_TYPE, 'Vehicule', $ref);
        }
        self::assertNotNull($h->habitude(self::EMETTEUR));

        $h->retenirChoix(self::EMETTEUR, Habitudes::CHAMP_TYPE, 'Courrier', 'doc-4');

        self::assertNull($h->habitude(self::EMETTEUR), 'le choix le plus récent contredit la série');
    }

    public function testUnMemeDocumentNeCompteQuUneFois(): void
    {
        $h = new Habitudes($this->pdo);
        foreach (['doc-1', 'doc-2', 'doc-3'] as $ref) {
            $h->retenirChoix(self::EMETTEUR, Habitudes::CHAMP_TYPE, 'Vehicule', $ref);
        }

        // L'utilisateur se ravise sur le doc-1 : le dernier choix fait foi, le document ne pèse qu'une fois.
        $h->retenirChoix(self::EMETTEUR, Habitudes::CHAMP_TYPE, 'Contrat', 'doc-1');

        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM classement_choix')->fetchColumn());
        self::assertNull($h->habitude(self::EMETTEUR), 'Contrat est désormais le plus récent, série de 1');
    }

    public function testLesChampsSontIndependants(): void
    {
        $h = new Habitudes($this->pdo);
        foreach (['doc-1', 'doc-2', 'doc-3'] as $ref) {
            $h->retenirChoix(self::EMETTEUR, Habitudes::CHAMP_TYPE, 'Vehicule', $ref);
            $h->retenirChoix(self::EMETTEUR, Habitudes::CHAMP_ETIQUETTES, '12,14', $ref);
        }

        self::assertSame('Vehicule', $h->habitude(self::EMETTEUR, Habitudes::CHAMP_TYPE)['valeur'] ?? null);
        self::assertSame('12,14', $h->habitude(self::EMETTEUR, Habitudes::CHAMP_ETIQUETTES)['valeur'] ?? null);
    }

    public function testLeTriAutomatiqueNEnseigneJamais(): void
    {
        $texte = "Garage du Banc d'Essai Sarl\nRue des Pistons 4\n2300 La Chaux-de-Fonds\nFACTURE N° GB-4410\nTotal TTC CHF 441.00\n";
        // Le tri automatique s'appuie sur l'IA ; même quand elle valide, elle n'enseigne rien.
        $ia = new class implements ClientIa {
            public function classer(string $texte, string $nomFichier, array $typesConnus): ?ReponseIa
            {
                return new ReponseIa('Facture', "Garage du Banc d'Essai Sarl", null, null, null, [], 0.95, 'simule');
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
        };
        $classeur = $this->classeur($ia);

        for ($i = 0; $i < 6; $i++) {
            $r = $classeur->proposer($texte, [], 'facture.pdf');
            self::assertTrue($r['valide']);
            self::assertSame('ia', $r['source']);
        }

        $h = new Habitudes($this->pdo);
        self::assertNull($h->habitude(self::EMETTEUR), 'une validation automatique n’apprend rien');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM classement_choix')->fetchColumn());
    }

    public function testLHabitudePrimeSurLaRegleEtValideSeule(): void
    {
        $h = new Habitudes($this->pdo);
        foreach (['doc-1', 'doc-2', 'doc-3'] as $ref) {
            $h->retenirChoix(self::EMETTEUR, Habitudes::CHAMP_TYPE, 'Vehicule', $ref);
        }

        // 4e pièce du même émetteur : aucun indice de type, mais l'habitude parle.
        $texte = "Garage du Banc d'Essai Sarl\nRue des Pistons 4\n2300 La Chaux-de-Fonds\nConcerne : Demande d'information\n";
        $r = $this->classeur()->proposer($texte, [], 'courrier.pdf');

        self::assertSame('Vehicule', $r['type']);
        self::assertSame('habitude', $r['source']);
        self::assertTrue($r['valide']);
    }
}
