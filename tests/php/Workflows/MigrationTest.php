<?php

declare(strict_types=1);

namespace Ged\Tests\Workflows;

use Ged\Workflows\ErreurWorkflow;
use Ged\Workflows\Migrations;
use PHPUnit\Framework\TestCase;

/**
 * Migrations versionnées du secteur : montée idempotente et retour NON destructif. Le lot
 * ged-parite-modele-1 ajoute la version 2 (colonne de tri `workflow_definitions.ordre`).
 */
final class MigrationTest extends TestCase
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

    public function testLaVersionSeLitDansLeSchemaEtMonteIdempotente(): void
    {
        self::assertSame(Migrations::VERSION, Migrations::version($this->pdo));
        self::assertTrue(Migrations::colonneExiste($this->pdo, 'workflow_definitions', 'ordre'));

        Migrations::monter($this->pdo);   // rejouer ne change rien
        self::assertSame(Migrations::VERSION, Migrations::version($this->pdo));
    }

    public function testMonterPuisRetournerEtRemonterSansPerdreLaDefinition(): void
    {
        Migrations::retour($this->pdo, 1);
        self::assertSame(1, Migrations::version($this->pdo));
        self::assertFalse(Migrations::colonneExiste($this->pdo, 'workflow_definitions', 'ordre'));

        Migrations::monter($this->pdo);
        self::assertSame(2, Migrations::version($this->pdo));

        $this->pdo->prepare("INSERT INTO workflow_definitions (genre, nom, ordre, corps) VALUES ('workflow', ?, 0, '{}')")
            ->execute(['Workflow migré']);

        Migrations::retour($this->pdo, 1);
        self::assertSame(1, Migrations::version($this->pdo));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM workflow_definitions WHERE nom = 'Workflow migré'")->fetchColumn());

        Migrations::monter($this->pdo);
        self::assertSame(2, Migrations::version($this->pdo));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM workflow_definitions WHERE nom = 'Workflow migré'")->fetchColumn());
    }

    public function testLeRetourEstRefuseQuandUnTriSeraitPerdu(): void
    {
        $this->pdo->prepare("INSERT INTO workflow_definitions (genre, nom, ordre, corps) VALUES ('workflow', ?, 7, '{}')")
            ->execute(['Workflow ordonné']);

        $this->expectException(ErreurWorkflow::class);
        $this->expectExceptionMessageMatches('/ordre non nul/');
        Migrations::retour($this->pdo, 1);

        // Rien n'a bougé : la colonne et la donnée de tri sont intactes.
        self::assertSame(2, Migrations::version($this->pdo));
        self::assertSame(7, (int) $this->pdo->query("SELECT ordre FROM workflow_definitions WHERE nom = 'Workflow ordonné'")->fetchColumn());
    }
}
