<?php

declare(strict_types=1);

namespace Ged\Tests\Recherche;

use Ged\Recherche\Schema;
use PHPUnit\Framework\TestCase;

/** Le schéma du secteur : table du plein texte présente, index FULLTEXT posé, installation rejouable. */
final class SchemaTest extends TestCase
{
    use SupportRecherche;

    protected function setUp(): void
    {
        $this->setUpRecherche();
    }

    protected function tearDown(): void
    {
        $this->tearDownRecherche();
    }

    public function testInstallerEstIdempotentEtPoseLeFulltext(): void
    {
        Schema::installer($this->pdo);   // deuxième appel sur une table déjà en place
        Schema::installer($this->pdo);

        $moteur = $this->pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = '" . Schema::TABLE . "'")->fetchColumn();
        self::assertSame('MyISAM', $moteur, 'index dérivé : moteur dont le FULLTEXT répond sur ce serveur');
        self::assertTrue(Schema::indexPleinTextePresent($this->pdo), 'index FULLTEXT présent');
        self::assertTrue(Schema::pleinTexteUtilisable($this->pdo), 'FULLTEXT utilisable');
    }

    public function testUnIndexFulltextRetireEstRepose(): void
    {
        $this->pdo->exec('ALTER TABLE ' . Schema::TABLE . ' DROP INDEX ' . Schema::INDEX_PLEIN_TEXTE);
        self::assertFalse(Schema::indexPleinTextePresent($this->pdo));

        Schema::assurerIndexPleinTexte($this->pdo);

        self::assertTrue(Schema::indexPleinTextePresent($this->pdo));
        self::assertTrue(Schema::pleinTexteUtilisable($this->pdo));
    }
}
