<?php

declare(strict_types=1);

namespace Ged\Tests\Contrats;

use Ged\Base;
use Ged\Classement\ClasseurGed;
use Ged\Classement\ClientIa;
use Ged\Classement\Habitudes;
use Ged\Classement\ReponseIa;
use Ged\Classement\Schema;
use Ged\Config;
use Ged\Contrats\Classeur;
use PDO;
use PHPUnit\Framework\TestCase;

/** Une proposition automatique est toujours explicable et jamais une catégorie fourre-tout. */
abstract class ClasseurContrat extends TestCase
{
    protected PDO $pdo;
    protected Config $config;

    protected function setUp(): void
    {
        $this->config = new Config(dirname(__DIR__, 2));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'));
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        Schema::installer($this->pdo);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    abstract protected function classeur(?ClientIa $ia = null): Classeur;

    public function testUnePropositionRespecteLaFormeDuContrat(): void
    {
        $p = $this->classeur()->proposer('Texte sans indice de classement.', [], 'piece.pdf');
        self::assertSame(['titre', 'type', 'emetteur', 'date', 'montant', 'etiquettes', 'confiance', 'source', 'preuve', 'valide', 'raison'], array_keys($p));
        self::assertIsBool($p['valide']);
        self::assertIsArray($p['etiquettes']);
        self::assertGreaterThanOrEqual(0.0, $p['confiance']);
        self::assertLessThanOrEqual(1.0, $p['confiance']);
    }

    public function testAutreNEstJamaisValideSeul(): void
    {
        $ia = new IaContrat(new ReponseIa('Autre', null, null, null, null, [], 0.99, 'contrat'));
        $p = $this->classeur($ia)->proposer('Bonjour, pièce non classable.', [], 'piece.pdf');
        self::assertSame('Autre', $p['type']);
        self::assertFalse($p['valide']);
        self::assertNotEmpty($p['raison']);
    }

    public function testUnTypeSansPreuveNEstJamaisValideSeul(): void
    {
        $ia = new IaContrat(new ReponseIa('Contrat', null, null, null, null, [], 0.99, 'contrat'));
        $p = $this->classeur($ia)->proposer('Bonjour, pièce sans mot-clé métier.', [], 'piece.pdf');
        self::assertSame('Contrat', $p['type']);
        self::assertFalse($p['valide']);
        self::assertNotEmpty($p['raison']);
    }

    public function testUneValidationAutomatiquePorteToujoursUnePreuve(): void
    {
        $ia = new IaContrat(new ReponseIa('Facture', 'Atelier Exemple SA', 'Facture F-2026-01', '2026-09-29', 12.50, [], 0.99, 'contrat'));
        $p = $this->classeur($ia)->proposer("Atelier Exemple SA\nFACTURE N° F-2026-01\nDate : 29.09.2026", [], 'piece.pdf');
        self::assertTrue($p['valide']);
        self::assertNotEmpty($p['preuve']);
    }

    public function testUneValidationAutomatiqueExigeUneDateCredibleEtConcordante(): void
    {
        $ia = new IaContrat(new ReponseIa('Facture', 'Atelier Exemple SA', null, null, null, [], 0.99, 'contrat'));
        $p = $this->classeur($ia)->proposer("Atelier Exemple SA\nFACTURE N° F-2026-02", [], 'piece.pdf');

        self::assertFalse($p['valide']);
        self::assertStringContainsString('date', (string) $p['raison']);
    }
}

final class IaContrat implements ClientIa
{
    public function __construct(private readonly ?ReponseIa $reponse) {}
    public function classer(string $texte, string $nomFichier, array $typesConnus): ?ReponseIa { return $this->reponse; }
    public function aExpire(): bool { return false; }
    public function disponible(): bool { return $this->reponse !== null; }
    public function modele(): string { return 'contrat'; }
}
