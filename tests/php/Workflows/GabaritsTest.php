<?php

declare(strict_types=1);

namespace Ged\Tests\Workflows;

use Ged\Workflows\Circuits;
use Ged\Workflows\Gabarits;
use Ged\Workflows\Regles;
use PHPUnit\Framework\TestCase;

/**
 * Porte 1 : les deux gabarits s'installent d'un geste, tournent sur le corpus, et les seuils routent
 * la facture vers le bon rôle. Le rappel de contrat sort à J-90 sur horloge figée.
 */
final class GabaritsTest extends TestCase
{
    use SupportWorkflows;

    protected function setUp(): void
    {
        $this->setUpWorkflows();
    }

    protected function tearDown(): void
    {
        $this->tearDownWorkflows();
    }

    public function testLesDeuxGabaritsSInstallentDUnGesteEtSansDoublon(): void
    {
        Gabarits::toutInstaller($this->pdo);
        $circuits = new Circuits($this->pdo);
        self::assertNotNull($circuits->parGabarit(Gabarits::FACTURE_A_SEUIL), 'facture à seuil installé');
        self::assertNotNull($circuits->parGabarit(Gabarits::CONTRAT_A_ECHEANCE), 'contrat à échéance installé');

        $avant = count($circuits->toutes());
        $reglesAvant = count((new Regles($this->pdo))->toutes());

        Gabarits::toutInstaller($this->pdo);   // second geste : idempotent

        self::assertCount($avant, $circuits->toutes(), 'aucun doublon de circuit');
        self::assertCount($reglesAvant, (new Regles($this->pdo))->toutes(), 'aucun doublon de règle');
    }

    public function testFactureEleveePartChezDirectionEtCouranteChezComptabilite(): void
    {
        Gabarits::installer($this->pdo, Gabarits::FACTURE_A_SEUIL);

        $elevee = $this->moteur()->essayer('document_depose', $this->fiche(1, ['montant' => 12000.0]));
        self::assertSame('direction', $this->assignation($elevee), '12 000 part chez direction');

        $courante = $this->moteur()->essayer('document_depose', $this->fiche(2, ['montant' => 300.0]));
        self::assertSame('comptabilite', $this->assignation($courante), '300 part chez comptabilité');
    }

    public function testLeSeuilEstInclusif(): void
    {
        Gabarits::installer($this->pdo, Gabarits::FACTURE_A_SEUIL);

        self::assertSame('direction', $this->assignation(
            $this->moteur()->essayer('document_depose', $this->fiche(1, ['montant' => 10000.0]))));
        self::assertSame('comptabilite', $this->assignation(
            $this->moteur()->essayer('document_depose', $this->fiche(2, ['montant' => 9999.99]))));
    }

    public function testContratAEcheanceProduitSonRappelAJ90(): void
    {
        Gabarits::installer($this->pdo, Gabarits::CONTRAT_A_ECHEANCE);

        // Horloge figée au 2026-04-01 : une échéance au 2026-06-30 est à exactement 90 jours.
        $res = $this->moteur()->essayer('echeance', $this->fiche(3, [
            'type' => 'Contrat', 'titre' => 'Contrat de maintenance CM-2026-04', 'montant' => null, 'echeance' => '2026-06-30',
        ]));

        $rappel = $this->etape($res, 'notifier');
        self::assertNotNull($rappel, 'le rappel part à J-90');
        self::assertSame('responsable', $rappel['destinataire']);
        self::assertStringContainsString('90', (string) $rappel['message']);
        self::assertStringContainsString('Contrat de maintenance CM-2026-04', (string) $rappel['message']);
    }

    public function testContratHorsDelaiNeRappellePas(): void
    {
        Gabarits::installer($this->pdo, Gabarits::CONTRAT_A_ECHEANCE);

        $res = $this->moteur()->essayer('echeance', $this->fiche(4, [
            'type' => 'Contrat', 'montant' => null, 'echeance' => '2026-12-31',
        ]));

        self::assertNull($this->etape($res, 'notifier'), 'pas de rappel avant J-90');
    }

    public function testUneFactureSansMontantNePartNiChezDirectionNiChezComptabilite(): void
    {
        Gabarits::installer($this->pdo, Gabarits::FACTURE_A_SEUIL);

        $res = $this->moteur()->essayer('document_depose', $this->fiche(5, ['montant' => null]));

        self::assertNull($this->assignation($res), 'un montant non lu ne route pas la validation');
    }

    /** @param array<string, mixed> $resultat */
    private function assignation(array $resultat): ?string
    {
        foreach ($resultat['etapes'] as $etape) {
            if ($etape['type'] === 'demarrer' && isset($etape['assigne'])) {
                return (string) $etape['assigne'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $resultat
     * @return array<string, mixed>|null
     */
    private function etape(array $resultat, string $type): ?array
    {
        foreach ($resultat['etapes'] as $etape) {
            if ($etape['type'] === $type) {
                return $etape;
            }
        }

        return null;
    }
}
