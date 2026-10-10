<?php

declare(strict_types=1);

namespace Ged\Tests\Securite;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\EcranBibliotheque;
use Ged\Socle\Schema as SchemaSocle;
use PHPUnit\Framework\TestCase;

/**
 * F10 — « 0 fuite » : un compte UTILISATEUR réel ne voit un document que dans les dossiers de droits
 * où une règle lui accorde au moins la lecture. Chaque liste est jugée séparément : le filtrage vit
 * DANS la requête (V2-13, point B3), jamais après coup.
 *
 * Jeu synthétique : un compte réel (rôle utilisateur, groupe propre), deux dossiers A et B, une règle
 * de lecture sur A seulement, un document par dossier. Rien de réel, aucun nom de client.
 */
final class FuiteListesTest extends TestCase
{
    private \PDO $pdo;
    private Config $config;
    private DroitsDossiers $droits;
    private string $suffixe;
    private int $compte;
    private int $groupe;
    private string $cheminA;
    private string $cheminB;
    private int $documentA;
    private int $documentB;
    private int $dossierA;
    private int $dossierB;

    protected function setUp(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        SchemaSocle::installer($this->pdo);
        SchemaSecurite::installer($this->pdo);
        $this->droits = new DroitsDossiers($this->pdo);
        $this->suffixe = bin2hex(random_bytes(5));

        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute(["fuite-{$this->suffixe}@gedv2.test", password_hash('Synthétique-' . $this->suffixe, PASSWORD_DEFAULT), 'utilisateur']);
        $this->compte = (int) $this->pdo->lastInsertId();
        $this->groupe = $this->droits->creerGroupe('fuite-' . $this->suffixe, 'utilisateur');
        $this->droits->ajouterCompteAuGroupe($this->compte, $this->groupe);

        [$this->documentA, $this->cheminA] = $this->creerDocument('A');
        [$this->documentB, $this->cheminB] = $this->creerDocument('B');
        $this->dossierA = (int) $this->droits->assurerDossierPourChemin($this->cheminA);
        $this->dossierB = (int) $this->droits->assurerDossierPourChemin($this->cheminB);
        $this->droits->poserRegle($this->dossierA, $this->groupe, 'lire', true);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare("DELETE FROM securite_dossiers_droits WHERE dossier_id IN (SELECT id FROM securite_dossiers WHERE chemin_relatif LIKE ?)")
            ->execute(['fuite-' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM securite_dossiers WHERE chemin_relatif LIKE ?')->execute(['fuite-' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM documents WHERE chemin_relatif LIKE ?')->execute(['fuite-' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM securite_groupes_comptes WHERE compte_id = ?')->execute([$this->compte]);
        $this->pdo->prepare('DELETE FROM securite_groupes WHERE id = ?')->execute([$this->groupe]);
        $this->pdo->prepare('DELETE FROM comptes WHERE id = ?')->execute([$this->compte]);
    }

    public function testLaListeDeLaBibliothequeNeMontreQueLesDocumentsDuDossierAutorise(): void
    {
        $donnees = (new EcranBibliotheque($this->config, $this->compte))->donnees([], 'jeton');
        $ids = array_map('intval', array_column($donnees['docs'], 'id'));

        self::assertContains($this->documentA, $ids, 'le document du dossier A (règle de lecture) reste visible');
        self::assertNotContains($this->documentB, $ids, 'le document du dossier B (aucun droit) ne doit jamais apparaître');
    }

    /** @return array{0:int, 1:string} */
    private function creerDocument(string $dossier): array
    {
        $chemin = "fuite-{$this->suffixe}/$dossier/document-$dossier.pdf";
        $this->pdo->prepare('INSERT INTO documents (chemin_relatif, nom, titre, type, empreinte, taille, mime, meta, statut)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$chemin, "document-$dossier.pdf", "Fuite $dossier {$this->suffixe}", "Type-$dossier-{$this->suffixe}",
                hash('sha256', $chemin), 1, 'application/pdf', '{}', 'valide']);

        return [(int) $this->pdo->lastInsertId(), $chemin];
    }
}
