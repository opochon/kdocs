<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Base;
use Ged\Classement\Correspondance;
use Ged\Config;
use Ged\Socle\Champs;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Export;
use Ged\Socle\Fichiers;
use Ged\Socle\ModificationMasse;
use Ged\Socle\ModeleDroits;
use Ged\Socle\Taches;
use Ged\Socle\Vues;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu de la famille `modele-reste` (lot ged-parite-modele-3).
 *
 * Vecteurs extraits en LECTURE SEULE des tests Paperless-ngx v2.20.5 par
 * `tools/parite/extraire-modele-reste.py` : nom public d'une pièce, algorithmes de correspondance,
 * réglages d'une vue enregistrée, validation d'une valeur de champ, cycle de vie d'une tâche suivie,
 * droits d'objet, opérations en masse, commandes d'export et découverte de parseur. Le rejeu passe par le
 * VRAI modèle GEDv2 et par la base `gedv2_test` ; les traits propres à Django/Celery/Tika sont listés
 * `non_rejouables` par l'extracteur (raison + référence) et ne sont pas comptés ici.
 */
final class ModeleResteTest extends TestCase
{
    private Config $config;
    private PDO $pdo;
    private string $racine;

    protected function setUp(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        \Ged\Socle\Schema::installer($this->pdo);

        $this->racine = sys_get_temp_dir() . '/gedv2-modele-reste-' . bin2hex(random_bytes(4));
        mkdir($this->racine . '/fonds', 0775, true);
        mkdir($this->racine . '/travail', 0775, true);
        putenv('STORAGE_ROOT=' . $this->racine . '/fonds');
    }

    protected function tearDown(): void
    {
        $this->nettoyerDocuments();
        putenv('STORAGE_ROOT');
        $this->supprimer($this->racine);
    }

    /** Supprime les seules lignes de ce rejeu (marquées dans leur `.meta.json`), jamais d'autre donnée. */
    private function nettoyerDocuments(): void
    {
        $ids = $this->pdo->query("SELECT id FROM documents WHERE meta LIKE '%rejeu-modele-reste%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            foreach (['document_versions', 'document_deplacements'] as $table) {
                // `$table` vient d'une liste interne figée : aucune entrée utilisateur dans le SQL.
                $this->pdo->prepare("DELETE FROM $table WHERE document_id = ?")->execute([(int) $id]);
            }
        }
        $this->pdo->exec("DELETE FROM documents WHERE meta LIKE '%rejeu-modele-reste%'");
    }

    public function testRejoueLesVecteursEtEcritLaMesure(): void
    {
        $donnees = Vecteurs::charger('modele-reste');
        self::assertSame('modele-reste', $donnees['categorie']);

        $passes = 0;
        $echecs = [];
        $familles = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $famille = (string) ($vecteur['famille'] ?? '?');
            $familles[$famille] ??= ['total' => 0, 'passes' => 0];
            $familles[$famille]['total']++;
            try {
                $this->rejouer($vecteur);
                $passes++;
                $familles[$famille]['passes']++;
            } catch (\Throwable $e) {
                $echecs[] = sprintf('%s (%s) : %s', (string) ($vecteur['id'] ?? '?'),
                    (string) ($vecteur['reference'] ?? ''), $e->getMessage());
            }
        }

        Vecteurs::ecrireMesure('modele-reste', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => 'modele-reste',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'familles' => $familles,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);

        self::assertSame([], $echecs, "vecteurs du reste du modèle en écart :\n" . implode("\n", $echecs));
    }

    /** @param array<string, mixed> $vecteur */
    private function rejouer(array $vecteur): void
    {
        $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
        $sortie = is_array($vecteur['sortie'] ?? null) ? $vecteur['sortie'] : [];
        $id = (string) ($vecteur['id'] ?? '');

        match ((string) ($vecteur['famille'] ?? '')) {
            'document' => $this->rejouerDocument($entree, $sortie, $id),
            'matching' => $this->rejouerMatching($entree, $id),
            'savedview' => $this->rejouerSavedview($entree, $sortie, $id),
            'champs' => $this->rejouerChamps($entree, $sortie, $id),
            'tache' => $this->rejouerTache($entree, $sortie, $id),
            'permissions' => $this->rejouerPermissions($entree, $id),
            'masse' => $this->rejouerMasse($entree, $sortie, $id),
            'commande' => $this->rejouerCommande($id),
            'chaine' => $this->rejouerChaine($entree, $sortie, $id),
            default => throw new \RuntimeException('famille inconnue'),
        };
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerDocument(array $entree, array $sortie, string $id): void
    {
        self::assertSame(
            (string) ($sortie['nom_public'] ?? ''),
            Fichiers::nomPublic((string) $entree['date'], (string) $entree['titre'], (string) $entree['mime']),
            $id,
        );
    }

    /** @param array<string, mixed> $entree */
    private function rejouerMatching(array $entree, string $id): void
    {
        $algorithme = (string) $entree['algorithme'];
        $motif = (string) $entree['motif'];
        $insensible = (bool) $entree['insensible'];
        foreach ((array) $entree['textes_match'] as $texte) {
            self::assertTrue(Correspondance::correspond($algorithme, $motif, (string) $texte, $insensible), "$id : « $texte » devrait correspondre");
        }
        foreach ((array) $entree['textes_non_match'] as $texte) {
            self::assertFalse(Correspondance::correspond($algorithme, $motif, (string) $texte, $insensible), "$id : « $texte » ne devrait pas correspondre");
        }
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerSavedview(array $entree, array $sortie, string $id): void
    {
        $vues = new Vues($this->config);
        $reglages = is_array($entree['reglages'] ?? null) ? $entree['reglages'] : [];
        if (($sortie['accepte'] ?? true) === false) {
            $this->expectException(\RuntimeException::class);
            $vues->compilerReglages($reglages);

            return;
        }
        $compiles = $vues->compilerReglages($reglages);
        if (array_key_exists('page_size', $reglages)) {
            self::assertSame((int) $reglages['page_size'], $compiles['taille_page'], $id);
        }
        if (array_key_exists('display_mode', $reglages)) {
            self::assertSame((string) $reglages['display_mode'], $compiles['mode'], $id);
        }
        if (array_key_exists('display_fields', $reglages)) {
            self::assertSame(array_values((array) $reglages['display_fields']), $compiles['affichage'], $id);
        }
        if (array_key_exists('show_on_dashboard', $reglages)) {
            self::assertSame((bool) $reglages['show_on_dashboard'], $compiles['tableau_bord'], $id);
        }
        if (array_key_exists('show_in_sidebar', $reglages)) {
            self::assertSame((bool) $reglages['show_in_sidebar'], $compiles['barre_laterale'], $id);
        }
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerChamps(array $entree, array $sortie, string $id): void
    {
        $accepte = (bool) ($sortie['accepte'] ?? true);
        try {
            Champs::validerValeur((string) $entree['nature'], [], $entree['valeur']);
            self::assertTrue($accepte, "$id : valeur refusée par GEDv2 mais acceptée par Paperless");
        } catch (\RuntimeException $e) {
            self::assertFalse($accepte, "$id : valeur acceptée par GEDv2 mais refusée par Paperless — " . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerTache(array $entree, array $sortie, string $id): void
    {
        $taches = new Taches($this->config);
        $tache = $taches->ouvrir('consume_file', 'MANUAL_TASK', (string) $entree['task_file_name'], 'olivia');
        self::assertSame((string) ($sortie['status'] ?? 'PENDING'), $tache['status'], $id);
        self::assertFalse($tache['acknowledged'], $id);
        self::assertNotSame('', (string) $tache['task_id'], $id);
        self::assertNotSame('', (string) $tache['date_created'], $id);
    }

    /** @param array<string, mixed> $entree */
    private function rejouerPermissions(array $entree, string $id): void
    {
        $action = ModeleDroits::action((string) $entree['codename']);
        self::assertContains($action, ['lire', 'modifier', 'gerer', 'deposer'], $id);
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerMasse(array $entree, array $sortie, string $id): void
    {
        $depot = new DepotFichiers($this->config);
        $ids = [];
        $jeton = bin2hex(random_bytes(4));
        foreach ([0, 1, 2] as $i) {
            $source = $this->piece('masse-' . $id . '-' . $i . '.pdf', 'contenu masse ' . $id . ' ' . $i);
            $ids[] = $depot->deposer($source, ['titre' => 'Masse ' . $jeton . ' ' . $i, 'type' => 'Facture', 'date' => '2026-09-0' . ($i + 1), 'origine' => ['rejeu-modele-reste' => true]]);
        }
        $service = new ModificationMasse($this->config);
        $job = $service->lancer($ids, ['action' => 'proprietaire', 'valeur' => (string) $entree['proprietaire']], 'olivia');
        $service->avancer((int) $job['id'], 10);
        foreach ($ids as $doc) {
            self::assertSame((string) $entree['proprietaire'], $depot->fiche($doc)['meta']['proprietaire'] ?? null, $id);
        }
        self::assertTrue((bool) ($sortie['proprietaire_pose'] ?? true), $id);
    }

    private function rejouerCommande(string $id): void
    {
        $export = Export::generer($this->pdo);
        self::assertArrayHasKey('documents', $export, $id);
        self::assertArrayHasKey('versions', $export, $id);
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerChaine(array $entree, array $sortie, string $id): void
    {
        self::assertSame((bool) ($sortie['supporte'] ?? false), Fichiers::extensionSupportee((string) $entree['extension']), $id);
    }

    private function piece(string $nom, string $contenu): string
    {
        $chemin = $this->racine . '/travail/' . bin2hex(random_bytes(4)) . '-' . $nom;
        file_put_contents($chemin, $contenu);

        return $chemin;
    }

    private function supprimer(string $chemin): void
    {
        if (!is_dir($chemin)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($chemin, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($chemin);
    }
}
