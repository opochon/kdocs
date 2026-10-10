<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;
use Ged\Socle\ModificationMasse;
use Ged\Socle\Schema as SchemaSocle;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du lot ged-parite-masse — modification en masse.
 *
 * Jeu synthétique : un compte MANAGER réel (groupe propre), deux dossiers ACL (« Modifiable » en
 * lecture + dépôt + modification, « Lecture » en lecture seule), des documents de test (fichiers
 * temporaires hors du dépôt). Rien de réel, aucun nom de client.
 *
 * Prouve :
 * 1. un document que le compte ne peut pas modifier est REFUSÉ, jamais modifié, et le bilan nomme
 *    la raison ; le document modifiable suit le MÊME service que l'édition unitaire (`.meta.json`,
 *    trace `document_deplacements`) ;
 * 2. 500 documents passent par paquets courts avec reprise (le curseur vit dans le fonds, `.ged/`) ;
 * 3. la corbeille est une MISE EN CORBEILLE (fichier sous `.corbeille/`, ligne drapeautée), jamais
 *    une suppression.
 */
final class ModificationMasseTest extends TestCase
{
    private \PDO $pdo;
    private Config $config;
    private DroitsDossiers $droits;
    private string $suffixe;
    private int $compte;
    private int $groupeModifiable;
    private int $groupeLecture;
    private int $compteur = 0;
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

        // Fonds documentaire de test isolé : jamais le dépôt de recette.
        $this->bac = sys_get_temp_dir() . '/gedv2-masse-' . $this->suffixe;
        if (!is_dir($this->bac . '/fonds') && !mkdir($this->bac . '/fonds', 0775, true) && !is_dir($this->bac . '/fonds')) {
            self::fail('fonds de test : création impossible');
        }
        $this->storageAvant = getenv('STORAGE_ROOT');
        putenv('STORAGE_ROOT=' . $this->bac . '/fonds');

        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute(["masse-{$this->suffixe}@gedv2.test", password_hash('Synthétique-' . $this->suffixe, PASSWORD_DEFAULT), 'manager']);
        $this->compte = (int) $this->pdo->lastInsertId();
        $this->groupeModifiable = $this->droits->creerGroupe('masse-mod-' . $this->suffixe, 'manager');
        $this->groupeLecture = $this->droits->creerGroupe('masse-lect-' . $this->suffixe, 'manager');
        $this->droits->ajouterCompteAuGroupe($this->compte, $this->groupeModifiable);
        $this->droits->ajouterCompteAuGroupe($this->compte, $this->groupeLecture);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM securite_dossiers_droits WHERE dossier_id IN (SELECT id FROM securite_dossiers WHERE chemin_relatif LIKE ?)')
            ->execute(['masse-' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM securite_dossiers WHERE chemin_relatif LIKE ?')->execute(['masse-' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM document_deplacements WHERE document_id IN (SELECT id FROM documents WHERE chemin_relatif LIKE ?)')
            ->execute(['%' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM document_versions WHERE document_id IN (SELECT id FROM documents WHERE chemin_relatif LIKE ?)')
            ->execute(['%' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM documents WHERE chemin_relatif LIKE ?')->execute(['%' . $this->suffixe . '%']);
        $this->pdo->prepare('DELETE FROM securite_groupes_comptes WHERE compte_id = ?')->execute([$this->compte]);
        $this->pdo->prepare('DELETE FROM securite_groupes WHERE nom LIKE ?')->execute(['masse-%' . $this->suffixe]);
        $this->pdo->prepare('DELETE FROM comptes WHERE id = ?')->execute([$this->compte]);
        $this->storageAvant === false ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageAvant);
        \Ged\Socle\Registre::oublier();
        $this->supprimerRecursif($this->bac);
    }

    public function testUnDocumentSansDroitDEcritureEstRefuseEtJamaisModifie(): void
    {
        $modifiable = $this->creerDocument('Modifiable', 'Facture');
        $lecture = $this->creerDocument('Lecture', 'Courrier');
        $this->poserDroits();

        $service = new ModificationMasse($this->config, $this->compte, false);
        $job = $service->lancer([$modifiable, $lecture], ['action' => 'type', 'valeur' => 'Contrat'], 'manager-test');
        $etat = $service->avancer((int) $job['id'], 50);

        self::assertSame('termine', $etat['etat']);
        self::assertSame(1, $etat['traites'], 'un seul document est modifiable');
        self::assertCount(1, $etat['refuses'], 'le document en lecture seule est refusé');
        self::assertSame($lecture, (int) $etat['refuses'][0]['id']);
        self::assertStringContainsString('droit', mb_strtolower((string) $etat['refuses'][0]['raison']));

        self::assertSame('Contrat', $this->typeIndexe($modifiable));
        self::assertSame('Courrier', $this->typeIndexe($lecture), 'le type du document refusé n’a PAS changé');
        self::assertSame('Contrat', $this->typeMeta($modifiable), 'le .meta.json du document modifié a suivi');
        self::assertSame('Courrier', $this->typeMeta($lecture), 'le .meta.json du document refusé est intact');
    }

    public function testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire(): void
    {
        $parMasse = $this->creerDocument('Modifiable', 'Facture');
        $unitaire = $this->creerDocument('Modifiable', 'Facture');
        $this->poserDroits();

        // Édition unitaire : le service de référence, appelé directement.
        (new DepotFichiers($this->config))->mettreAJour($unitaire, ['type' => 'Contrat'], 'auteur-unitaire');

        $service = new ModificationMasse($this->config, $this->compte, false);
        $job = $service->lancer([$parMasse], ['action' => 'type', 'valeur' => 'Contrat'], 'auteur-masse');
        $service->avancer((int) $job['id'], 50);

        $reference = $this->traceDeplacement($unitaire);
        $obtenu = $this->traceDeplacement($parMasse);
        self::assertNotNull($obtenu, 'la modification en masse passe par la même trace de déplacement');
        self::assertSame($reference['cause'], $obtenu['cause'], 'mêmes effets que l’édition unitaire (même service)');
        self::assertSame($reference['auteur'], 'auteur-unitaire');
        self::assertSame('auteur-masse', $obtenu['auteur']);
        self::assertNotNull($this->metaDeplacement($parMasse), 'le .meta.json est déplacé à côté du fichier');
        self::assertSame('Contrat', $this->typeMeta($parMasse));
    }

    public function testCinqCentsDocumentsPassentParPaquetsAvecReprise(): void
    {
        $ids = [];
        for ($i = 1; $i <= 500; $i++) {
            $ids[] = $this->creerDocument('Modifiable', 'Facture');
        }
        $this->poserDroits();

        $service = new ModificationMasse($this->config, $this->compte, false);
        $job = $service->lancer($ids, ['action' => 'etiquettes_ajouter', 'valeur' => 'Lot-500'], 'manager-test');
        $id = (int) $job['id'];

        $tranches = 0;
        $precedent = 0;
        do {
            // Un service NEUF à chaque tranche : l'état vient du fonds (.ged/), pas d'une mémoire de requête.
            $service = new ModificationMasse($this->config, $this->compte, false);
            $etat = $service->avancer($id, 50);
            $tranches++;
            self::assertLessThanOrEqual(50, $etat['traite'] - $precedent, 'chaque tranche reste bornée');
            self::assertSame(500, $etat['total']);
            self::assertLessThan(20, $tranches, 'les paquets restent courts');
            $precedent = $etat['traite'];
        } while ($etat['etat'] === 'en_cours');

        self::assertSame(500, $etat['traites'], '500 documents traités');
        self::assertCount(0, $etat['refuses']);
        self::assertGreaterThanOrEqual((int) ceil(500 / 50), $tranches, 'au moins 10 paquets courts');

        // Le libellé est posé sur CHAQUE `.meta.json` (le dossier fait foi), sans doublon.
        $dernier = (int) $ids[count($ids) - 1];
        $chemin = (string) $this->ligne($dernier)['chemin_relatif'];
        $meta = json_decode((string) file_get_contents($this->cheminFonds($chemin) . '.meta.json'), true);
        self::assertIsArray($meta);
        self::assertContains('Lot-500', (array) ($meta['etiquettes'] ?? []));
        self::assertSame(1, array_count_values((array) $meta['etiquettes'])['Lot-500'] ?? 0, 'aucun doublon d’étiquette');
    }

    public function testLaCorbeilleEstUneMiseEnCorbeilleJamaisUneSuppression(): void
    {
        $modifiable = $this->creerDocument('Modifiable', 'Facture');
        $lecture = $this->creerDocument('Lecture', 'Courrier');
        $this->poserDroits();

        $service = new ModificationMasse($this->config, $this->compte, false);
        $cheminAvant = (string) $this->ligne($modifiable)['chemin_relatif'];
        $job = $service->lancer([$modifiable, $lecture], ['action' => 'corbeille'], 'manager-test');
        $etat = $service->avancer((int) $job['id'], 50);

        self::assertSame(1, $etat['traites']);
        self::assertCount(1, $etat['refuses']);
        self::assertFileDoesNotExist($this->cheminFonds($cheminAvant), 'la ligne vivante n’est plus à sa place');
        self::assertFileExists($this->cheminFonds('.corbeille/' . $cheminAvant), 'le fichier est conservé à la corbeille');
        $ligne = $this->ligne($modifiable);
        self::assertNotNull($ligne['supprime_le'], 'la ligne est drapeautée, jamais supprimée');
        self::assertSame('Courrier', $this->typeIndexe($lecture), 'le document refusé reste en place');
    }

    private function poserDroits(): void
    {
        $modifiable = $this->droits->assurerDossierPourChemin('masse-' . $this->suffixe . '/Modifiable/document.pdf');
        $lecture = $this->droits->assurerDossierPourChemin('masse-' . $this->suffixe . '/Lecture/document.pdf');
        self::assertIsInt($modifiable);
        self::assertIsInt($lecture);
        foreach (['lire', 'deposer', 'modifier'] as $niveau) {
            $this->droits->poserRegle($modifiable, $this->groupeModifiable, $niveau, true);
        }
        $this->droits->poserRegle($lecture, $this->groupeLecture, 'lire', true);
    }

    private function creerDocument(string $dossier, string $type): int
    {
        $this->compteur++;
        $chemin = 'masse-' . $this->suffixe . '/' . $dossier . '/piece-' . $this->suffixe . '-' . $this->compteur . '.pdf';
        $this->pdo->prepare('INSERT INTO documents (chemin_relatif, nom, titre, type, empreinte, taille, mime, meta, statut)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$chemin, basename($chemin), 'Document ' . $this->compteur . ' ' . $this->suffixe, $type,
                hash('sha256', $chemin), 1, 'application/pdf',
                json_encode(['titre' => 'Document ' . $this->compteur, 'type' => $type, 'etiquettes' => []], JSON_UNESCAPED_UNICODE), 'a_controler']);
        $document = (int) $this->pdo->lastInsertId();
        $absolu = $this->cheminFonds($chemin);
        if (!is_dir(dirname($absolu)) && !mkdir(dirname($absolu), 0775, true) && !is_dir(dirname($absolu))) {
            self::fail('fonds de test : création du rangement impossible');
        }
        file_put_contents($absolu, "%PDF-1.4\n% masse synthetique\n");
        file_put_contents($absolu . '.meta.json', (string) json_encode(['titre' => 'Document ' . $this->compteur, 'type' => $type, 'etiquettes' => []], JSON_UNESCAPED_UNICODE));

        return $document;
    }

    /** Chemin absolu d'un chemin relatif du fonds d'essai. */
    private function cheminFonds(string $relatif): string
    {
        return $this->bac . '/fonds/' . $relatif;
    }

    private function ligne(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM documents WHERE id = ?');
        $stmt->execute([$id]);
        $ligne = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($ligne);

        return $ligne;
    }

    private function typeIndexe(int $id): ?string
    {
        $type = $this->ligne($id)['type'];

        return is_string($type) ? $type : null;
    }

    private function typeMeta(int $id): ?string
    {
        $chemin = (string) $this->ligne($id)['chemin_relatif'];
        $meta = json_decode((string) file_get_contents($this->cheminFonds($chemin) . '.meta.json'), true);

        return is_array($meta) && isset($meta['type']) ? (string) $meta['type'] : null;
    }

    /** @return array<string, mixed>|null */
    private function traceDeplacement(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document_deplacements WHERE document_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$id]);
        $ligne = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $ligne === false ? null : $ligne;
    }

    private function metaDeplacement(int $id): ?string
    {
        $chemin = (string) $this->ligne($id)['chemin_relatif'];

        return is_file($this->cheminFonds($chemin) . '.meta.json') ? $chemin : null;
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
