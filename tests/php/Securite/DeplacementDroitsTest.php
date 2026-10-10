<?php

declare(strict_types=1);

namespace Ged\Tests\Securite;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\RefusDepot;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;
use Ged\Socle\ModificationMasse;
use Ged\Socle\Schema as SchemaSocle;
use Ged\Ui\ATraiter;
use Ged\Workflows\Fonds as FondsWorkflows;
use Ged\Workflows\Moteur;
use Ged\Workflows\Regles;
use Ged\Workflows\Schema as SchemaWorkflows;
use PHPUnit\Framework\TestCase;

/**
 * F10 — un changement de métadonnées ne fait jamais entrer un document dans un dossier où le compte
 * n'a pas le droit de DÉPOSER.
 *
 * Défaut couvert : `DepotFichiers::mettreAJour` recalcule le rangement depuis les métadonnées et
 * déplace le fichier ; les droits étaient vérifiés sur le dossier de DÉPART (modifier) mais jamais sur
 * le dossier d'ARRIVÉE. Un manager qui a `modifier` sur A pouvait, en changeant le type, faire entrer
 * un document dans B sans y avoir aucun droit.
 *
 * Jeu synthétique : un compte MANAGER réel (groupe propre), un type A et un type B uniques à ce rejeu
 * (les dossiers de rangement sont donc propres au test), un émetteur unique, un document rangé par les
 * métadonnées. Rien de réel, aucun nom de client.
 *
 * Le contrôle vit dans LE SERVICE (`DepotFichiers::mettreAJour`), avant le déplacement, avec le compte
 * de l'appelant passé explicitement : les oracles ci-dessous l'exercent par le service (le chemin de la
 * fiche), par la validation « À traiter » et par la modification en masse, plus le moteur de workflows.
 */
final class DeplacementDroitsTest extends TestCase
{
    private \PDO $pdo;
    private Config $config;
    private DroitsDossiers $droits;
    private string $suffixe;
    private string $emetteur;
    private string $typeA;
    private string $typeB;
    private int $compte;
    private int $groupe;
    private string $racine;
    private string|false $storageAvant;

    protected function setUp(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        SchemaSocle::installer($this->pdo);
        SchemaSecurite::installer($this->pdo);
        SchemaWorkflows::installer($this->pdo);
        \Ged\Classement\Schema::installer($this->pdo);
        $this->droits = new DroitsDossiers($this->pdo);

        $this->suffixe = bin2hex(random_bytes(5));
        $this->emetteur = 'Emetteur ' . $this->suffixe;
        $this->typeA = 'DeplA' . $this->suffixe;
        $this->typeB = 'DeplB' . $this->suffixe;

        $this->racine = sys_get_temp_dir() . '/gedv2-deplacement-' . $this->suffixe;
        if (!is_dir($this->racine) && !mkdir($this->racine, 0775, true) && !is_dir($this->racine)) {
            self::fail('fonds de test : création impossible');
        }
        $this->storageAvant = getenv('STORAGE_ROOT');
        putenv('STORAGE_ROOT=' . $this->racine);

        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute(["depl-{$this->suffixe}@gedv2.test", password_hash('Synthetique-' . $this->suffixe, PASSWORD_DEFAULT), 'manager']);
        $this->compte = (int) $this->pdo->lastInsertId();
        $this->groupe = $this->droits->creerGroupe('depl-' . $this->suffixe, 'manager');
        $this->droits->ajouterCompteAuGroupe($this->compte, $this->groupe);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM securite_dossiers_droits WHERE dossier_id IN (SELECT id FROM securite_dossiers WHERE chemin_relatif LIKE ?)')
            ->execute(['%' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM securite_dossiers WHERE chemin_relatif LIKE ?')->execute(['%' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM document_deplacements WHERE document_id IN (SELECT id FROM documents WHERE chemin_relatif LIKE ?)')
            ->execute(['%' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM document_versions WHERE document_id IN (SELECT id FROM documents WHERE chemin_relatif LIKE ?)')
            ->execute(['%' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM documents WHERE chemin_relatif LIKE ?')->execute(['%' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM workflow_definitions WHERE nom LIKE ?')->execute(['%' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM classement_choix WHERE emetteur_cle = ?')->execute(['emetteur ' . $this->suffixe]);
        $this->pdo->prepare('DELETE FROM securite_groupes_comptes WHERE compte_id = ?')->execute([$this->compte]);
        $this->pdo->prepare('DELETE FROM securite_groupes WHERE nom LIKE ?')->execute(['depl-%' . $this->suffixe]);
        $this->pdo->prepare('DELETE FROM comptes WHERE id = ?')->execute([$this->compte]);
        $this->storageAvant === false ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageAvant);
        \Ged\Socle\Registre::oublier();
        $this->supprimerRecursif($this->racine);
    }

    /** Oracle « fiche » (le service) : l'arrivée refusée ne déplace ni fichier, ni meta, ni index. */
    public function testLeServiceRefuseUneArriveeSansDroitDeDepotEtNeBougeRien(): void
    {
        [$id, $cheminA] = $this->deposerPiece($this->typeA, 'valide');
        $this->accorder(dirname($cheminA), ['lire', 'deposer', 'modifier']);

        try {
            (new DepotFichiers($this->config))->mettreAJour($id, ['type' => $this->typeB], 'manager', $this->compte, false);
            self::fail('un changement de rangement sans droit de dépôt sur l’arrivée doit être refusé');
        } catch (RefusDepot $e) {
            self::assertStringContainsString('deposer', $e->getMessage(), 'le niveau manquant est nommé');
            self::assertStringContainsString($this->dossierArrivee($cheminA), $e->getMessage(), 'le dossier visé est nommé');
        }

        $this->assertPieceIntacte($id, $cheminA, $this->typeA);
    }

    public function testLeServiceAccepteQuandLeManagerADeposerSurLArrivee(): void
    {
        [$id, $cheminA] = $this->deposerPiece($this->typeA, 'valide');
        $this->accorder(dirname($cheminA), ['lire', 'deposer', 'modifier']);
        $this->accorder($this->dossierArrivee($cheminA), ['lire', 'deposer']);

        (new DepotFichiers($this->config))->mettreAJour($id, ['type' => $this->typeB], 'manager', $this->compte, false);

        self::assertSame($this->typeB, $this->segmentType($id), 'le document est rangé sous le type d’arrivée');
        self::assertFileExists($this->racine . '/' . $this->cheminIndexe($id), 'le document est bien entré dans B');
        self::assertFileDoesNotExist($this->racine . '/' . $cheminA, 'il a quitté le dossier de départ');
        self::assertSame(1, $this->deplacements($id), 'le déplacement est tracé');
    }

    public function testUnAdministrateurNEstJamaisRefuse(): void
    {
        [$id, $cheminA] = $this->deposerPiece($this->typeA, 'valide');

        (new DepotFichiers($this->config))->mettreAJour($id, ['type' => $this->typeB], 'admin', null, true);

        self::assertSame($this->typeB, $this->segmentType($id), 'administrateur : le rangement change sans ACL');
    }

    /** Oracle « À traiter » : la validation d'un manager refuse et NOMME l'arrivée sans droit. */
    public function testLaValidationATraiterRefuseUneArriveeSansDroitDeDepot(): void
    {
        [$id, $cheminA] = $this->deposerPiece($this->typeA, 'a_controler');
        $this->accorder(dirname($cheminA), ['lire', 'deposer', 'modifier']);

        $atraiter = new ATraiter($this->config, $this->pdo, null, null, $this->compte, false);
        try {
            $atraiter->valider($id, $this->champs($this->typeB), 'manager');
            self::fail('la validation ne doit pas ranger dans un dossier sans droit de dépôt');
        } catch (RefusDepot $e) {
            self::assertStringContainsString('deposer', $e->getMessage());
            self::assertStringContainsString($this->dossierArrivee($cheminA), $e->getMessage(), 'le dossier visé est nommé');
        }

        $this->assertPieceIntacte($id, $cheminA, $this->typeA);
        self::assertSame('a_controler', $this->statutIndexe($id), 'le statut n’a pas été validé');
    }

    public function testLaValidationATraiterAccepteQuandLeManagerADeposerSurLArrivee(): void
    {
        [$id, $cheminA] = $this->deposerPiece($this->typeA, 'a_controler');
        $this->accorder($this->dossierArrivee($cheminA), ['lire', 'deposer']);

        (new ATraiter($this->config, $this->pdo, null, null, $this->compte, false))
            ->valider($id, $this->champs($this->typeB), 'manager');

        self::assertSame('valide', $this->statutIndexe($id));
        self::assertSame($this->typeB, $this->segmentType($id), 'le document a rejoint le dossier autorisé');
    }

    /**
     * Oracle « lot À traiter » : le document refusé est compté dans les refus, avec sa raison. La pièce
     * a été DÉPOSÉE sous A puis re-proposée sous B (métadonnées seules) : le lot la validerait en B,
     * où le manager n'a pas de droit de dépôt.
     */
    public function testLaValidationDeLotCompteLeDocumentRefuseAvecSaRaison(): void
    {
        [$id, $cheminA] = $this->deposerPiece($this->typeA, 'a_controler');
        $this->reproposerType($id, $cheminA, $this->typeB);
        $this->accorder(dirname($cheminA), ['lire', 'deposer', 'modifier']);

        $bilan = (new ATraiter($this->config, $this->pdo, null, null, $this->compte, false))
            ->validerPropositions([$id], 'manager');

        self::assertSame([], $bilan['valides'], 'le lot n’a rien validé');
        self::assertArrayHasKey($id, $bilan['refuses'], 'le document est compté dans les refus');
        self::assertStringContainsString('deposer', $bilan['refuses'][$id], 'la raison nomme le niveau manquant');
        self::assertFileExists($this->racine . '/' . $cheminA, 'le fichier est resté dans A');
        self::assertSame($cheminA, $this->cheminIndexe($id), 'l’index n’a pas bougé');
        self::assertSame(0, $this->deplacements($id), 'aucun déplacement n’est tracé');
    }

    /** Oracle « modification en masse » : un seul document est refusé, nommé, jamais déplacé. */
    public function testLaModificationEnMasseRefuseLArriveeSansDroitEtCompteLaRaison(): void
    {
        [$id, $cheminA] = $this->deposerPiece($this->typeA, 'valide');
        $this->accorder(dirname($cheminA), ['lire', 'deposer', 'modifier']);

        $service = new ModificationMasse($this->config, $this->compte, false);
        $job = $service->lancer([$id], ['action' => 'type', 'valeur' => $this->typeB], 'manager');
        $etat = $service->avancer((int) $job['id'], 50);

        self::assertSame('termine', $etat['etat']);
        self::assertSame(0, $etat['traites'], 'aucun document n’est traité');
        self::assertCount(1, $etat['refuses'], 'le document est compté dans les refus');
        self::assertSame($id, (int) $etat['refuses'][0]['id']);
        self::assertStringContainsString('deposer', (string) $etat['refuses'][0]['raison'], 'la raison nomme le niveau manquant');
        $this->assertPieceIntacte($id, $cheminA, $this->typeA);

        // Le même manager, avec le droit de dépôt sur l'arrivée, voit la modification aboutir.
        $this->accorder($this->dossierArrivee($cheminA), ['lire', 'deposer']);
        $service = new ModificationMasse($this->config, $this->compte, false);
        $job = $service->lancer([$id], ['action' => 'type', 'valeur' => $this->typeB], 'manager');
        $etat = $service->avancer((int) $job['id'], 50);

        self::assertSame(1, $etat['traites']);
        self::assertCount(0, $etat['refuses']);
        self::assertSame($this->typeB, $this->segmentType($id), 'le document a rejoint le dossier autorisé');
    }

    /** Oracle « action de workflow » : une règle qui pose un type passe par le même service, avec le compte. */
    public function testUneActionDeWorkflowRefuseUneArriveeSansDroitDeDepot(): void
    {
        [$id, $cheminA] = $this->deposerPiece($this->typeA, 'valide');
        $this->accorder(dirname($cheminA), ['lire', 'deposer', 'modifier']);
        (new Regles($this->pdo))->creer([
            'nom' => 'Poser le type B ' . $this->suffixe,
            'declencheur' => 'document_depose',
            'conditions' => [],
            'actions' => [['type' => 'poser', 'champ' => 'type', 'valeur' => $this->typeB]],
            'actif' => true,
        ]);

        $fonds = FondsWorkflows::depuis($this->config, $this->pdo);
        $fiche = (array) $fonds->parId($id);
        $plan = (new Moteur($this->pdo, new DepotFichiers($this->config)))
            ->executer('document_depose', $fiche, 'manager', $this->compte, false);

        self::assertNotEmpty($plan['erreurs'], 'la règle qui change de rangement est refusée et signalée');
        self::assertStringContainsString('deposer', (string) $plan['erreurs'][0]['cause']);
        $this->assertPieceIntacte($id, $cheminA, $this->typeA);

        // Même règle, droit de dépôt accordé sur l'arrivée : le rangement suit.
        $this->accorder($this->dossierArrivee($cheminA), ['lire', 'deposer']);
        $plan = (new Moteur($this->pdo, new DepotFichiers($this->config)))
            ->executer('document_depose', $fiche, 'manager', $this->compte, false);

        self::assertSame([], $plan['erreurs']);
        self::assertSame($this->typeB, $this->segmentType($id), 'le document a rejoint le dossier autorisé');
    }

    /** Oracle « retouche de pages » : le rangement est contrôlé AVANT le contenu ; un refus ne touche à rien. */
    public function testLaRetoucheDePagesRefuseUneArriveeSansDroitEtLaisseLeContenuIntact(): void
    {
        [$id, $cheminA] = $this->deposerPdf($this->typeA);
        $this->accorder(dirname($cheminA), ['lire', 'deposer', 'modifier']);
        $contenuAvant = (string) file_get_contents($this->racine . '/' . $cheminA);

        $edition = new \Ged\Ingestion\EditionPieces($this->config, new DepotFichiers($this->config),
            $this->classeur($this->typeB), $this->compte, false);

        try {
            $edition->tourner($id, 1, 90, 'manager');
            self::fail('la retouche ne doit pas ranger dans un dossier sans droit de dépôt');
        } catch (RefusDepot $e) {
            self::assertStringContainsString('deposer', $e->getMessage());
        }

        self::assertSame($contenuAvant, (string) file_get_contents($this->racine . '/' . $cheminA),
            'le contenu du fichier est intact');
        self::assertSame($cheminA, $this->cheminIndexe($id), 'l’index n’a pas bougé');
        self::assertSame(0, $this->deplacements($id), 'aucun déplacement n’est tracé');
        self::assertSame(1, $this->versions($id), 'aucune version n’a été archivée : seule la v1 du dépôt');
    }

    private function classeur(string $type): \Ged\Contrats\Classeur
    {
        $emetteur = $this->emetteur;

        return new class($type, $emetteur) implements \Ged\Contrats\Classeur {
            public function __construct(private readonly string $type, private readonly string $emetteur)
            {
            }

            public function proposer(string $texte, array $analyse, string $nomFichier): array
            {
                return ['titre' => 'Pièce retouchée', 'type' => $this->type, 'emetteur' => $this->emetteur,
                    'date' => '2026-05-01', 'montant' => null, 'etiquettes' => [], 'confiance' => 0.4,
                    'source' => 'regle', 'preuve' => 'oracle', 'valide' => false, 'raison' => 'à contrôler'];
            }
        };
    }

    /** Dépose un VRAI PDF d'une page, à contrôler, avec son origine de pages. */
    private function deposerPdf(string $type): array
    {
        $source = $this->racine . '/source-pdf-' . bin2hex(random_bytes(4)) . '.pdf';
        $script = "import pymupdf as fitz\n"
            . "doc=fitz.open()\n"
            . "doc.new_page(width=595,height=842)\n"
            . "doc.save(r'" . str_replace('\\', '\\\\', $source) . "')\n";
        $processus = proc_open([(string) $this->config->get('CMD4_PYTHON'), '-c', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($processus);
        fclose($pipes[1]);
        $erreur = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($processus), $erreur);

        $id = (new DepotFichiers($this->config))->deposer($source, [
            'titre' => 'Pièce PDF ' . $this->suffixe,
            'type' => $type,
            'emetteur' => $this->emetteur,
            'date' => '2026-05-01',
            'statut' => 'a_controler',
            'origine' => ['lot' => 1, 'pages' => '1', 'pages_liste' => [1]],
        ]);
        $chemin = (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();

        return [$id, $chemin];
    }

    private function versions(int $id): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM document_versions WHERE document_id = ' . $id)->fetchColumn();
    }

    /** @return array<string, string> champs de validation « À traiter », même émetteur que la pièce. */
    private function champs(string $type): array
    {
        return ['type' => $type, 'emetteur' => $this->emetteur, 'date' => '2026-05-01'];
    }

    /**
     * Simule une pièce « en attente » dont la proposition a changé SANS que le fichier ait bougé (le
     * cas d'un document re-proposé par l'ingestion avant validation) : les métadonnées annoncent le type
     * visé, le fichier reste dans son dossier de dépôt.
     */
    private function reproposerType(int $id, string $chemin, string $type): void
    {
        $ligne = $this->pdo->prepare('SELECT meta FROM documents WHERE id = ?');
        $ligne->execute([$id]);
        $meta = json_decode((string) $ligne->fetchColumn(), true);
        self::assertIsArray($meta);
        $meta['type'] = $type;
        $this->pdo->prepare('UPDATE documents SET meta = ? WHERE id = ?')
            ->execute([(string) json_encode($meta, JSON_UNESCAPED_UNICODE), $id]);
        file_put_contents($this->racine . '/' . $chemin . '.meta.json', (string) json_encode($meta, JSON_UNESCAPED_UNICODE));
    }

    /** Dépose une pièce par le VRAI service et rend [id, chemin relatif]. */
    private function deposerPiece(string $type, string $statut): array
    {
        $source = $this->racine . '/source-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($source, "%PDF-1.4\n% pièce synthétique " . $this->suffixe . "\n%%EOF\n");
        $id = (new DepotFichiers($this->config))->deposer($source, [
            'titre' => 'Pièce ' . $this->suffixe . ' ' . substr(hash('sha1', $type . $statut), 0, 6),
            'type' => $type,
            'emetteur' => $this->emetteur,
            'date' => '2026-05-01',
            'statut' => $statut,
        ]);
        $chemin = (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();

        return [$id, $chemin];
    }

    /** Dossier de droits de l'arrivée : même chemin que le départ, mais le SEGMENT DE TYPE devient B. */
    private function dossierArrivee(string $cheminA): string
    {
        $segments = explode('/', dirname($cheminA));
        $segments[1] = $this->typeB;

        return implode('/', $segments);
    }

    /** @param list<string> $niveaux */
    private function accorder(string $dossier, array $niveaux): void
    {
        $id = $this->droits->assurerDossierPourChemin($dossier . '/piece.pdf');
        self::assertIsInt($id, "dossier ACL attendu : $dossier");
        foreach ($niveaux as $niveau) {
            $this->droits->poserRegle($id, $this->groupe, $niveau, true);
        }
    }

    private function assertPieceIntacte(int $id, string $cheminA, string $typeA): void
    {
        self::assertFileExists($this->racine . '/' . $cheminA, 'le fichier n’a pas bougé');
        self::assertFileExists($this->racine . '/' . $cheminA . '.meta.json', 'le .meta.json n’a pas bougé');
        $meta = json_decode((string) file_get_contents($this->racine . '/' . $cheminA . '.meta.json'), true);
        self::assertIsArray($meta);
        self::assertSame($typeA, $meta['type'] ?? null, 'le type du .meta.json est intact');
        self::assertSame($cheminA, $this->cheminIndexe($id), 'l’index pointe toujours sur le dossier de départ');
        self::assertSame(0, $this->deplacements($id), 'aucun déplacement n’est tracé');
    }

    private function cheminIndexe(int $id): string
    {
        return (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();
    }

    /** Deuxième segment du chemin rangé : l'année, puis le type. */
    private function segmentType(int $id): string
    {
        return explode('/', $this->cheminIndexe($id))[1] ?? '';
    }

    private function statutIndexe(int $id): string
    {
        return (string) $this->pdo->query('SELECT statut FROM documents WHERE id = ' . $id)->fetchColumn();
    }

    private function deplacements(int $id): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM document_deplacements WHERE document_id = ' . $id)->fetchColumn();
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
