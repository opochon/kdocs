<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\DepotFichiers;
use Ged\Socle\Indexeur;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-01 §3 et §4 (flux F6, F7) : le dossier fait foi.
 * Modifier un fichier hors application → au passage de l'indexeur, v2 archivée dans `.versions/`, v1 intacte.
 * Déplacer/renommer un dossier hors application → l'index suit (la base suit le dossier, jamais l'inverse).
 */
final class IndexeurTest extends TestCase
{
    use SupportCorpus;

    private DepotFichiers $depot;
    private Indexeur $indexeur;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        $this->depot = new DepotFichiers($this->config);
        $this->indexeur = new Indexeur($this->config);
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testUneModificationHorsApplicationArchiveLaV2EtLaisseLaV1Intacte(): void
    {
        $id = $this->depot->deposer($this->pieceADeposer('pv.pdf', 'contenu v1'), [
            'titre' => 'PV réception', 'type' => 'pv', 'date' => '2026-06-06',
        ]);
        $rel = '2026/pv/PV_réception.pdf';

        // Le fichier est réécrit DEPUIS l'extérieur de l'application (explorateur, logiciel tiers).
        file_put_contents($this->racine . '/fonds/' . $rel, 'contenu v2 modifié');

        $this->indexeur->passer();
        $r = $this->indexeur->passer();   // un relevé de stabilité, puis le traitement (comme le dossier surveillé)

        self::assertSame(1, $r['versions'], 'une nouvelle version constatée');
        $archives = $this->versions($rel);
        self::assertCount(2, $archives, 'v1 ET v2 archivées');
        self::assertSame('v001_' . substr(hash('sha256', 'contenu v1'), 0, 8) . '.pdf', $archives[0]);
        self::assertSame('v002_' . substr(hash('sha256', 'contenu v2 modifié'), 0, 8) . '.pdf', $archives[1]);
        self::assertStringContainsString('contenu v1', (string) file_get_contents(
            dirname($this->racine . '/fonds/' . $rel) . '/.versions/' . basename($rel) . '/' . $archives[0]),
            'la version 1 est intacte');
        self::assertSame('contenu v2 modifié', file_get_contents($this->racine . '/fonds/' . $rel),
            'le fichier courant porte le nouveau contenu');

        $ligne = $this->pdo->query('SELECT empreinte, taille FROM documents WHERE id = ' . $id)->fetch();
        self::assertSame(hash('sha256', 'contenu v2 modifié'), $ligne['empreinte'], 'l’index suit le contenu');
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM document_versions WHERE document_id = ' . $id)->fetchColumn());
    }

    public function testUnDossierRenommeHorsApplicationEstSuiviSansPerdreLesVersions(): void
    {
        $id = $this->depot->deposer($this->pieceADeposer('lettre.pdf', 'lettre'), [
            'titre' => 'Lettre officielle', 'type' => 'lettre', 'emetteur' => 'Cabinet Y', 'date' => '2026-07-07',
        ]);
        $avant = '2026/lettre/Cabinet_Y/Lettre_officielle.pdf';

        // Renommage du dossier EMETTEUR depuis l'explorateur : fichiers, .meta.json et .versions suivent.
        rename($this->racine . '/fonds/2026/lettre/Cabinet_Y', $this->racine . '/fonds/2026/lettre/Cabinet_Z');

        $this->indexeur->passer();
        $r = $this->indexeur->passer();

        $apres = '2026/lettre/Cabinet_Z/Lettre_officielle.pdf';
        self::assertSame(1, $r['deplaces'], 'un déplacement constaté');
        self::assertSame($apres,
            $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn(),
            'la ligne suit le dossier, avec le même identifiant');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn(),
            'aucun doublon créé par le déplacement');
        $trace = $this->pdo->query('SELECT * FROM document_deplacements WHERE document_id = ' . $id)->fetch();
        self::assertSame($avant, $trace['chemin_avant']);
        self::assertSame($apres, $trace['chemin_apres']);
        self::assertSame('indexeur', $trace['auteur'], 'le déplacement est constaté, pas commandé');
        self::assertSame(['v001_' . substr(hash('sha256', 'lettre'), 0, 8) . '.pdf'], $this->versions($apres),
            'les versions restent reliées');
    }

    public function testUnFichierDeposeALaMainEstIndexeAvecSesMetadonnees(): void
    {
        $cible = $this->racine . '/fonds/2025/contrat/Atelier_X';
        mkdir($cible, 0775, true);
        file_put_contents("$cible/contrat-cadre.pdf", 'contrat posé à la main');
        file_put_contents("$cible/contrat-cadre.pdf.meta.json", json_encode([
            'titre' => 'Contrat cadre', 'type' => 'contrat', 'emetteur' => 'Atelier X', 'date' => '2025-12-01',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->indexeur->passer();
        $r = $this->indexeur->passer();

        self::assertSame(1, $r['nouveaux']);
        $ligne = $this->pdo->query('SELECT * FROM documents')->fetch();
        self::assertSame('2025/contrat/Atelier_X/contrat-cadre.pdf', $ligne['chemin_relatif']);
        self::assertSame('Contrat cadre', $ligne['titre'], 'les métadonnées du dossier font foi');
        self::assertSame('contrat', $ligne['type']);
        self::assertSame(hash('sha256', 'contrat posé à la main'), $ligne['empreinte']);
        self::assertSame(['v001_' . substr(hash('sha256', 'contrat posé à la main'), 0, 8) . '.pdf'],
            $this->versions('2025/contrat/Atelier_X/contrat-cadre.pdf'), 'v1 posée à l’indexation');
    }

    public function testUnFichierDeposeALaMainSansMetaAEstTitreParSonNom(): void
    {
        mkdir($this->racine . '/fonds/2025/notes', 0775, true);
        file_put_contents($this->racine . '/fonds/2025/notes/note-du-jour.txt', 'note');

        $this->indexeur->passer();
        $this->indexeur->passer();

        $ligne = $this->pdo->query('SELECT titre, statut FROM documents')->fetch();
        self::assertSame('note-du-jour', $ligne['titre']);
        self::assertSame('a_controler', $ligne['statut'], 'sans provenance connue, le document attend un contrôle');
    }

    public function testUnFichierToujoursEnEcritureNEstPasIndexe(): void
    {
        mkdir($this->racine . '/fonds/2026/brut', 0775, true);
        file_put_contents($this->racine . '/fonds/2026/brut/scanner.pdf', 'moitié du fichier');
        $r = $this->indexeur->passer();   // premier relevé : la signature n’est pas encore stable
        self::assertSame(0, $r['nouveaux'], 'un fichier vu pour la première fois n’est pas traité');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn());
    }

    public function testUnDocumentDisparuSansTraceResteDansLindex(): void
    {
        $id = $this->depot->deposer($this->pieceADeposer('x.pdf', 'x'), ['titre' => 'X']);
        $relatif = (string) $this->depot->fiche($id)['chemin_relatif'];
        unlink($this->racine . '/fonds/' . $relatif);

        $this->indexeur->passer();
        $this->indexeur->passer();

        $ligne = $this->pdo->query('SELECT absent_depuis, supprime_le FROM documents WHERE id = ' . $id)->fetch();
        self::assertNotNull($ligne['absent_depuis'], 'la disparition est datée');
        self::assertNull($ligne['supprime_le'], 'disparu n’est pas supprimé : zéro destruction');
    }

    public function testUnMetaModifieHorsApplicationEstAdopte(): void
    {
        $id = $this->depot->deposer($this->pieceADeposer('d.pdf', 'd'), [
            'titre' => 'Titre déposé', 'type' => 'divers', 'date' => '2026-08-08',
        ]);
        $rel = '2026/divers/Titre_déposé.pdf';
        $meta = $this->meta($rel);
        $meta['titre'] = 'Titre corrigé à la main';
        file_put_contents($this->racine . '/fonds/' . $rel . '.meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->indexeur->passer();
        $r = $this->indexeur->passer();

        self::assertSame(1, $r['metas']);
        self::assertSame('Titre corrigé à la main',
            $this->pdo->query('SELECT titre FROM documents WHERE id = ' . $id)->fetchColumn(),
            'le .meta.json fait foi aussi sur les métadonnées');
    }
}
