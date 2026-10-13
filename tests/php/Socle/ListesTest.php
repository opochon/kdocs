<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\Listes;
use PHPUnit\Framework\TestCase;

/**
 * Famille M-Files nº 1 (lot ged-mfiles-classes-1) : les LISTES DE VALEURS NOMMÉES sont des
 * vocabulaires contrôlés réutilisables (Statut, Type de pièce, Exercice) qu'une propriété de classe
 * référence. Elles vivent dans le fonds (`.ged/listes.json`, via Registre) : configuration portable,
 * versionnable et reconstruisible — le dossier fait foi, la base reste un index.
 *
 * Intégrité : une valeur retirée n'est jamais effacée du dossier ; elle reste lisible, datée et
 * attribuée, et n'est plus proposée.
 */
final class ListesTest extends TestCase
{
    use SupportCorpus;

    private Listes $listes;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        $this->listes = new Listes($this->config);
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testLaGrainePorteLesVocabulairesLivresEtEstIdempotente(): void
    {
        $this->listes->assurer();
        $this->listes->assurer();

        $noms = array_column($this->listes->lister(), 'nom');
        self::assertSame(['Statut', 'Type de pièce', 'Exercice'], $noms,
            'la graine porte les trois vocabulaires du plan, dans l’ordre');
        self::assertCount(3, $this->listes->lister(), 'assurer est idempotent');
        self::assertNotEmpty($this->listes->valeursDe('statut'), 'le vocabulaire Statut est alimenté');
    }

    public function testUneListeVitDansLeFondsPasDansLaBase(): void
    {
        $this->listes->assurer();
        $this->listes->ajouter('Service');

        $fichier = $this->racine . '/fonds/.ged/listes.json';
        self::assertFileExists($fichier, 'les listes vivent dans le fonds, à côté des documents');
        $surDisque = json_decode((string) file_get_contents($fichier), true) ?: [];
        self::assertContains('Service', array_column($surDisque['listes'] ?? [], 'nom'),
            'le fichier dit les listes : le dossier fait foi, même pour la configuration');

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotContains('socle_listes', $tables, 'aucune table nouvelle : le plafond de 20 tient');
    }

    public function testAjouterRenommerDesactiverSansJamaisSupprimer(): void
    {
        $this->listes->assurer();
        $id = $this->listes->ajouter('Service');

        self::assertSame('service', $this->listes->trouver($id)['cle'] ?? '', 'la clé est le slug du nom');

        $this->listes->renommer($id, 'Service concerné');
        self::assertContains('Service concerné', array_column($this->listes->lister(), 'nom'));

        $this->listes->definirActif($id, false);
        self::assertNotContains('Service concerné', array_column($this->listes->lister(), 'nom'),
            'une liste désactivée n’est plus proposée');
        self::assertContains('Service concerné', array_column($this->listes->lister(false), 'nom'),
            'mais elle existe toujours : des documents peuvent vivre avec ses valeurs');
        self::assertSame('service', $this->listes->trouver($id)['cle'] ?? '',
            'désactiver ne change jamais la clé : les propriétés qui la référencent restent adressées');

        $this->listes->definirActif($id, true);
        self::assertContains('Service concerné', array_column($this->listes->lister(), 'nom'));
    }

    public function testUnDoublonOuUnNomVideEstRefuse(): void
    {
        $this->listes->assurer();
        foreach (['  statut ', ''] as $nom) {
            try {
                $this->listes->ajouter($nom);
                self::fail("la liste « $nom » devait être refusée");
            } catch (\RuntimeException $e) {
                self::assertNotSame('', trim($e->getMessage()), 'l’erreur se nomme');
            }
        }
        self::assertCount(3, $this->listes->lister());
    }

    public function testAjoutRenommageEtRetraitTraceDuneValeur(): void
    {
        $this->listes->assurer();
        $statut = $this->listes->parCle('statut');
        self::assertNotNull($statut);

        $id = $this->listes->ajouterValeur((int) $statut['id'], 'En attente de visa');
        self::assertContains('En attente de visa', $this->listes->valeursDe('statut'));

        $this->listes->renommerValeur((int) $statut['id'], $id, 'En attente de visa direction');
        self::assertContains('En attente de visa direction', $this->listes->valeursDe('statut'));

        $this->listes->retirerValeur((int) $statut['id'], $id, 'admin@gedv2.test');

        self::assertNotContains('En attente de visa direction', $this->listes->valeursDe('statut'),
            'une valeur retirée n’est plus proposée');
        self::assertFalse($this->listes->estValeurActive('statut', 'En attente de visa direction'));

        $retiree = $this->listes->valeur((int) $statut['id'], $id);
        self::assertNotNull($retiree, 'la valeur retirée reste lisible dans le dossier : rien n’est effacé');
        self::assertSame('En attente de visa direction', $retiree['libelle']);
        self::assertFalse($retiree['actif']);
        self::assertNotEmpty($retiree['retire_le'], 'le retrait est daté');
        self::assertSame('admin@gedv2.test', $retiree['retire_par'], 'le retrait est attribué');
        self::assertContains('En attente de visa direction', $this->listes->valeursDe('statut', false),
            'la lecture inclusive garde la valeur retirée traçable');

        $this->listes->reactiverValeur((int) $statut['id'], $id);
        self::assertTrue($this->listes->estValeurActive('statut', 'En attente de visa direction'));
    }

    public function testListerLesValeursDuneListeTrieEtBorne(): void
    {
        $this->listes->assurer();
        $id = $this->listes->ajouter('Exercice test');
        $this->listes->ajouterValeur($id, '2026');
        $this->listes->ajouterValeur($id, '2025');

        self::assertSame(['2026', '2025'], $this->listes->valeursDe('exercice-test'),
            'les valeurs sont rendues dans l’ordre de création, par clé de liste');
    }

    public function testUneListeOuUneValeurInconnueEstNommee(): void
    {
        $this->listes->assurer();
        try {
            $this->listes->ajouterValeur(999999, 'x');
            self::fail('une liste inconnue devait être refusée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('999999', $e->getMessage());
        }
        try {
            $this->listes->renommerValeur(1, 999999, 'x');
            self::fail('une valeur inconnue devait être refusée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('999999', $e->getMessage());
        }
    }

    public function testResoudreUnAliasDeListe(): void
    {
        $this->listes->assurer();
        $id = $this->listes->ajouter('Type de pièce comptable');
        self::assertSame($id, $this->listes->resoudreAlias('type-de-piece-comptable'),
            'un alias (clé ou slug) résout l’identifiant de la liste');
        self::assertNull($this->listes->resoudreAlias('inexistant'));
    }

    public function testCompletRendLesValeursPourLAdministration(): void
    {
        $this->listes->assurer();
        $id = $this->listes->ajouter('Service');
        $valeurId = $this->listes->ajouterValeur($id, 'Audit');
        $this->listes->retirerValeur($id, $valeurId, 'admin@gedv2.test');

        $service = null;
        foreach ($this->listes->complet() as $liste) {
            if ($liste['nom'] === 'Service') {
                $service = $liste;
            }
        }
        self::assertNotNull($service, 'la lecture complète rend chaque liste avec ses valeurs');
        self::assertSame('Audit', $service['valeurs'][0]['libelle']);
        self::assertFalse($service['valeurs'][0]['actif'], 'la lecture d’administration voit les valeurs retirées');
        self::assertArrayHasKey('retire_le', $service['valeurs'][0]);
    }
}
