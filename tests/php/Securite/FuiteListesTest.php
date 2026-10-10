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
 * de lecture sur A seulement, un document par dossier (fichiers temporaires hors du dépôt). Rien de
 * réel, aucun nom de client.
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
    private string $bac;
    private string|false $storageAvant;

    protected function setUp(): void
    {
        $this->suffixe = bin2hex(random_bytes(5));
        $this->config = new Config(dirname(__DIR__, 3));
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        SchemaSocle::installer($this->pdo);
        SchemaSecurite::installer($this->pdo);
        $this->droits = new DroitsDossiers($this->pdo);

        // Fonds documentaire de test isolé : les preuves « fichier présent » lisent ICI, jamais le dépôt.
        $this->bac = sys_get_temp_dir() . '/gedv2-fuite-' . $this->suffixe;
        if (!is_dir($this->bac . '/fonds') && !mkdir($this->bac . '/fonds', 0775, true) && !is_dir($this->bac . '/fonds')) {
            self::fail('fonds de test : création impossible');
        }
        $this->storageAvant = getenv('STORAGE_ROOT');
        putenv('STORAGE_ROOT=' . $this->bac . '/fonds');

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
        $this->pdo->prepare('DELETE FROM securite_dossiers_droits WHERE dossier_id IN (SELECT id FROM securite_dossiers WHERE chemin_relatif LIKE ?)')
            ->execute(['fuite-' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM securite_dossiers WHERE chemin_relatif LIKE ?')->execute(['fuite-' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM documents WHERE chemin_relatif LIKE ?')->execute(['fuite-' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM securite_groupes_comptes WHERE compte_id = ?')->execute([$this->compte]);
        $this->pdo->prepare('DELETE FROM securite_groupes WHERE id = ?')->execute([$this->groupe]);
        $this->pdo->prepare('DELETE FROM comptes WHERE id = ?')->execute([$this->compte]);
        $this->storageAvant === false ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageAvant);
        $this->supprimerRecursif($this->bac);
    }

    public function testLaListeDeLaBibliothequeNeMontreQueLesDocumentsDuDossierAutorise(): void
    {
        $donnees = (new EcranBibliotheque($this->config, $this->compte))->donnees([], 'jeton');
        $ids = array_map('intval', array_column($donnees['docs'], 'id'));

        self::assertSame([$this->documentA], $ids, 'seul le document du dossier A (règle de lecture) est visible');
        self::assertNotContains($this->documentB, $ids, 'le document du dossier B (aucun droit) ne doit jamais apparaître');
    }

    public function testLesCompteursLesVuesEtLArborescenceNeFuitentPasHorsDroits(): void
    {
        $donnees = (new EcranBibliotheque($this->config, $this->compte))->donnees([], 'jeton');

        self::assertSame(1, $donnees['total'], 'le compteur du fonds ne compte que les documents lisibles');
        self::assertCount(1, $donnees['vuesVolet']);
        self::assertSame(1, $donnees['vuesVolet'][0]['compte'], 'la vue « Tous les documents » ne compte que les documents lisibles');
        self::assertSame(['fuite-' . $this->suffixe, 'fuite-' . $this->suffixe . '/A'], array_column($donnees['arbre'], 'chemin'),
            'l’arborescence ne montre que les dossiers qui portent un document lisible');
    }

    public function testLePanneauDApercuNeMontrePasUnDocumentHorsDroits(): void
    {
        $donnees = (new EcranBibliotheque($this->config, $this->compte))->donnees(['doc' => $this->documentB], 'jeton');

        self::assertIsArray($donnees['apercu']);
        self::assertNotSame($this->documentB, $donnees['apercu']['id'],
            'demander ?doc=<hors droits> ne doit jamais afficher ce document dans l’aperçu');
    }

    public function testATraiterNeListePasUnDocumentHorsDroits(): void
    {
        $donnees = \Ged\Ui\ATraiter::depuisRacine($this->config->racine(), $this->compte)->donnees([], 'jeton');
        $ids = array_map('intval', array_column($donnees['file'], 'id'));

        self::assertSame([$this->documentA], $ids, 'la file « À traiter » ne contient que le document du dossier autorisé');
        self::assertSame(1, $donnees['bilan']['a_controler'], 'le compteur de la file ne compte que les documents lisibles');
    }

    /** @return array{0:int, 1:string} */
    private function creerDocument(string $dossier): array
    {
        $chemin = "fuite-{$this->suffixe}/$dossier/document-$dossier.pdf";
        $this->pdo->prepare('INSERT INTO documents (chemin_relatif, nom, titre, type, empreinte, taille, mime, meta, statut)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$chemin, "document-$dossier.pdf", "Fuite $dossier {$this->suffixe}", "Type-$dossier-{$this->suffixe}",
                hash('sha256', $chemin), 1, 'application/pdf', '{}', 'a_controler']);
        $absolu = $this->bac . '/fonds/' . $chemin;
        if (!is_dir(dirname($absolu)) && !mkdir(dirname($absolu), 0775, true) && !is_dir(dirname($absolu))) {
            self::fail('fonds de test : création du rangement impossible');
        }
        file_put_contents($absolu, "%PDF-1.4\n% fuite synthetique\n");
        file_put_contents($absolu . '.meta.json', '{"titre":"Fuite ' . $dossier . '","type":"Type-' . $dossier . '-' . $this->suffixe . '"}');

        return [(int) $this->pdo->lastInsertId(), $chemin];
    }

    private function supprimerRecursif(string $chemin): void
    {
        if (!is_dir($chemin)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($chemin, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($chemin);
    }
}
