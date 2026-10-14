<?php

declare(strict_types=1);

namespace Ged\Tests\EspacePartage;

use Ged\EspacePartage\Partages;
use Ged\Securite\DroitsDossiers;
use PHPUnit\Framework\TestCase;

/**
 * Oracle des liens de partage de fichier (lot ged-mfiles-espace-1) : lister, créer, ouvrir (télécharger via
 * le lien) et révoquer. Le jeton public n'est jamais conservé en clair (ni base, ni journal) ; un lien
 * inconnu, expiré ou révoqué se refuse en se nommant ; une liste ne révèle pas un document hors droits.
 */
final class PartagesTest extends TestCase
{
    use SupportEspace;

    protected function setUp(): void
    {
        $this->setUpEspace();
    }

    protected function tearDown(): void
    {
        $this->tearDownEspace();
    }

    public function testCreerListerEtOuvrirUnLienDePartageSansSecretEnClair(): void
    {
        $id = $this->deposerPiece('Pièce partagée');
        $partages = new Partages($this->config);

        $lien = $partages->creer($id, 'alice@exemple.test', '2099-01-01');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{40,}$/', $lien['jeton']);

        $liens = $partages->liens();
        self::assertCount(1, $liens);
        self::assertSame($id, $liens[0]['document']);
        self::assertSame('Pièce partagée', $liens[0]['titre']);
        self::assertSame('2099-01-01 23:59:59', $liens[0]['expire_le']);

        $ouvert = $partages->ouvrir($lien['jeton']);
        self::assertNotNull($ouvert);
        self::assertSame($id, $ouvert['document']);

        // Le jeton n'est jamais conservé en clair, ni dans la base, ni dans le journal d'audit.
        $empreintes = (string) $this->pdo->query("SELECT GROUP_CONCAT(jeton_empreinte) FROM document_collaboration WHERE type = 'partage'")->fetchColumn();
        self::assertStringNotContainsString($lien['jeton'], $empreintes);
        $audit = $this->racine . '/securite/audit.ndjson';
        self::assertFileExists($audit);
        self::assertStringNotContainsString($lien['jeton'], (string) file_get_contents($audit));
    }

    public function testOuvrirRefuseUnJetonInconnuEtUnLienExpire(): void
    {
        $id = $this->deposerPiece('Pièce expirée');
        $partages = new Partages($this->config);

        self::assertNull($partages->ouvrir(str_repeat('x', 43)), 'un jeton inconnu ne doit rien ouvrir');

        $lien = $partages->creer($id, 'alice@exemple.test', '2099-01-02');
        $this->pdo->prepare("UPDATE document_collaboration SET expire_le = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?")
            ->execute([$lien['id']]);
        self::assertNull($partages->ouvrir($lien['jeton']), 'un lien expiré ne doit rien ouvrir');
    }

    public function testRevoquerParCleFermeLeLienEtRefuseUneCleInconnue(): void
    {
        $id = $this->deposerPiece('Pièce révoquée');
        $partages = new Partages($this->config);
        $lien = $partages->creer($id, 'alice@exemple.test', '2099-01-03');
        self::assertNotNull($partages->ouvrir($lien['jeton']));

        $partages->revoquerParCle($lien['jeton'], 'alice@exemple.test');
        self::assertNull($partages->ouvrir($lien['jeton']), 'un lien révoqué ne doit plus rien ouvrir');
        self::assertSame([], $partages->liens(), 'un lien révoqué sort de la liste');

        $this->expectExceptionMessage('introuvable ou déjà révoqué');
        $partages->revoquerParCle($lien['jeton'], 'alice@exemple.test');
    }

    public function testLaListeDesLiensNeRevelePasUnDocumentHorsDroits(): void
    {
        $id = $this->deposerPiece('Pièce réservée', 'espace/reserve');
        $partages = new Partages($this->config);
        $partages->creer($id, 'alice@exemple.test', '2099-01-04');

        $compte = $this->compteUnique();
        self::assertSame([], $partages->liens($compte), 'sans droit de lecture, rien ne fuit');

        $droits = new DroitsDossiers($this->pdo);
        $groupe = $droits->creerGroupe('Espace ' . bin2hex(random_bytes(3)), 'lecteur');
        $droits->ajouterCompteAuGroupe($compte, $groupe);
        $dossier = $droits->dossierPourDocument($id);
        self::assertNotNull($dossier);
        $droits->poserRegle($dossier, $groupe, 'lire', true);

        self::assertSame([$id], array_column($partages->liens($compte), 'document'));
    }

    public function testUnCompteSansDroitNePeutPasRevoquerLeLienDAutrui(): void
    {
        $id = $this->deposerPiece('Pièce réservée', 'espace/reserve');
        $partages = new Partages($this->config);
        $lien = $partages->creer($id, 'alice@exemple.test', '2099-01-05');
        $compte = $this->compteUnique();

        // Sans droit de lecture sur le document, la révocation par identifiant est refusée et le lien reste ouvert.
        try {
            $partages->revoquer($lien['id'], $compte, 'bob@exemple.test');
            self::fail('une révocation hors droits doit être refusée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('hors de vos droits', $e->getMessage());
        }
        self::assertNotNull($partages->ouvrir($lien['jeton']));

        // Une fois le droit accordé, la révocation aboutit.
        $droits = new DroitsDossiers($this->pdo);
        $groupe = $droits->creerGroupe('Espace ' . bin2hex(random_bytes(3)), 'lecteur');
        $droits->ajouterCompteAuGroupe($compte, $groupe);
        $dossier = $droits->dossierPourDocument($id);
        self::assertNotNull($dossier);
        $droits->poserRegle($dossier, $groupe, 'lire', true);
        $partages->revoquer($lien['id'], $compte, 'bob@exemple.test');
        self::assertNull($partages->ouvrir($lien['jeton']));
    }
}
