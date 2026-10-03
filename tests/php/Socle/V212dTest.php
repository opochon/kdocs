<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Socle\Collaboration;
use Ged\Socle\DepotFichiers;
use Ged\Socle\ReconstructionIndex;
use PHPUnit\Framework\TestCase;

/** Oracle V2-12d : ces gestes ne peuvent pas être de simples boutons de la fiche. */
final class V212dTest extends TestCase
{
    use SupportCorpus;

    private string|false $auditAvant;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        $this->auditAvant = getenv('SECURITE_DOSSIER');
        putenv('SECURITE_DOSSIER=' . $this->racine . '/audit');
        SchemaRecherche::installer($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->auditAvant === false ? putenv('SECURITE_DOSSIER') : putenv('SECURITE_DOSSIER=' . $this->auditAvant);
        $this->tearDownCorpus();
    }

    public function testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex(): void
    {
        $id = $this->document('note.txt', 'Pièce note', 'texte du document');
        $index = IndexPleinTexte::depuis($this->config, $this->pdo);
        $index->synchroniser();
        $collaboration = new Collaboration($this->config);

        $note = $collaboration->ajouterNote($id, 'alice@exemple.test', 'Relancer le fournisseur synthétique');
        $collaboration->modifierNote($note, 'alice@exemple.test', 'Relancer le fournisseur fictif');
        self::assertSame('Relancer le fournisseur fictif', $collaboration->notes($id)[0]['texte']);
        self::assertStringContainsString('fournisseur fictif', (string) $this->pdo->query('SELECT contenu FROM recherche_textes WHERE document_id = ' . $id)->fetchColumn());
        self::assertStringContainsString('Note modifiée par alice@exemple.test', implode(' ', array_column($collaboration->historique($id), 'quoi')));
        $this->expectExceptionMessage('seule votre propre note');
        $collaboration->modifierNote($note, 'bob@exemple.test', 'intrusion');
    }

    public function testUneNoteRetireeSortDeLIndexEtUnLienEstVisibleDesDeuxCotes(): void
    {
        $source = $this->document('source.txt', 'Facture synthétique', 'source');
        $cible = $this->document('cible.txt', 'Contrat synthétique', 'cible');
        $index = IndexPleinTexte::depuis($this->config, $this->pdo);
        $index->synchroniser();
        $collaboration = new Collaboration($this->config);

        $note = $collaboration->ajouterNote($source, 'alice@exemple.test', 'motclefretirable');
        $lien = $collaboration->lier($source, $cible, 'facture vers contrat', 'alice@exemple.test', null);
        self::assertSame([$cible], array_column($collaboration->liens($source), 'document'));
        self::assertSame([$source], array_column($collaboration->liens($cible), 'document'));
        self::assertStringContainsString('facture vers contrat', implode(' ', array_column($collaboration->historique($source), 'quoi')));

        $collaboration->retirerNote($note, 'alice@exemple.test');
        $collaboration->retirerLien($lien, 'alice@exemple.test');
        self::assertSame([], $collaboration->notes($source));
        self::assertSame([], $collaboration->liens($source));
        self::assertStringNotContainsString('motclefretirable', (string) $this->pdo->query('SELECT contenu FROM recherche_textes WHERE document_id = ' . $source)->fetchColumn());
    }

    public function testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair(): void
    {
        $id = $this->document('partage.txt', 'Partage synthétique', 'aperçu');
        $collaboration = new Collaboration($this->config);
        $partage = $collaboration->partagerExterne($id, 'manager@exemple.test', '2099-01-01');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{40,}$/', $partage['jeton']);
        self::assertNull($collaboration->lienExterneValide(str_repeat('x', 43)));
        self::assertSame($id, $collaboration->lienExterneValide($partage['jeton'])['document']);
        self::assertStringNotContainsString($partage['jeton'], (string) $this->pdo->query('SELECT GROUP_CONCAT(jeton_empreinte) FROM document_partages')->fetchColumn());
        self::assertStringNotContainsString($partage['jeton'], (string) file_get_contents($this->racine . '/audit/audit.ndjson'));

        $collaboration->revoquerPartage($partage['id'], 'manager@exemple.test');
        self::assertNull($collaboration->lienExterneValide($partage['jeton']));
        $expire = $collaboration->partagerExterne($id, 'manager@exemple.test', '2099-01-02');
        $this->pdo->prepare('UPDATE document_partages SET expire_le = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?')->execute([$expire['id']]);
        self::assertNull($collaboration->lienExterneValide($expire['jeton']));
    }

    public function testLePartageInterneNeCreePasDeDroitEtNeListeQueLesDestinatairesAutorises(): void
    {
        $id = $this->document('interne.txt', 'Interne', 'partage interne');
        \Ged\Securite\Schema::installer($this->pdo);
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), role=VALUES(role)')->execute(['lecteur@exemple.test', 'x', 'lecteur']);
        $lecteur = (int) $this->pdo->lastInsertId();
        $droits = new \Ged\Securite\DroitsDossiers($this->pdo);
        $groupe = $droits->creerGroupe('Lecteurs V2-12d', 'lecteur'); $droits->ajouterCompteAuGroupe($lecteur, $groupe);
        $dossier = $droits->dossierPourDocument($id); self::assertNotNull($dossier);
        $collaboration = new Collaboration($this->config);
        $this->expectExceptionMessage('doit déjà lire le dossier');
        try { $collaboration->partagerInterne($id, 'compte', $lecteur, 'alice@exemple.test'); }
        finally {
            $droits->poserRegle($dossier, $groupe, 'lire', true);
            $partage = $collaboration->partagerInterne($id, 'compte', $lecteur, 'alice@exemple.test');
            self::assertSame([$id], array_column($collaboration->partagesAvecMoi($lecteur), 'document'));
            self::assertGreaterThan(0, $partage);
        }
    }

    public function testLaReconstructionEstBorneeRepriseEtRefuseUnSecondLancement(): void
    {
        $this->document('reconstruire-a.txt', 'A', 'premier');
        $this->document('reconstruire-b.txt', 'B', 'second');
        $index = IndexPleinTexte::depuis($this->config, $this->pdo);
        $index->synchroniser();
        $job = new ReconstructionIndex($this->config);
        $lance = $job->lancer('admin@exemple.test');
        self::assertSame('en_cours', $lance['etat']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM recherche_textes WHERE absent_le IS NOT NULL')->fetchColumn());
        try {
            $job->lancer('admin@exemple.test');
            self::fail('un second lancement doit être refusé');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('déjà en cours', $e->getMessage());
        }
        $un = $job->avancer($lance['id'], 1);
        self::assertSame(1, $un['traite']);
        self::assertSame('en_cours', $un['etat']);
        $fini = $job->avancer($lance['id'], 1);
        self::assertSame('termine', $fini['etat']);
        self::assertSame(2, $fini['traite']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM recherche_textes WHERE absent_le IS NOT NULL')->fetchColumn());
    }

    private function document(string $nom, string $titre, string $texte): int
    {
        return (new DepotFichiers($this->config))->deposer($this->pieceADeposer($nom, $texte), [
            'titre' => $titre, 'type' => 'Facture', 'emetteur' => 'Atelier Fictif SA',
            'date' => '2026-10-03', 'texte' => $texte, 'statut' => 'valide',
        ]);
    }
}
