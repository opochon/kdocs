<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\Champs;
use Ged\Socle\Collaboration;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Etiquettes;
use Ged\Socle\Matching;
use Ged\Socle\ModeleDroits;
use Ged\Socle\Preferences;
use Ged\Socle\Taches;
use Ged\Socle\Vues;
use PHPUnit\Framework\TestCase;

/**
 * Modèles du reste de `modele` (lot ged-parite-modele-3), à la manière GEDv2 : étiquettes (Tag.color /
 * is_inbox_tag), préférences d'interface (UiSettings), appariement (MatchingModel), suivi de tâches
 * (PaperlessTask), champs url/lien (FieldDataType.URL / DOCUMENTLINK), réglages de vue (SavedView),
 * traduction des droits d'objet (PaperlessObjectPermissions) et version partagée (ShareLink.file_version).
 */
final class ModelesResteTest extends TestCase
{
    use SupportCorpus;

    protected function setUp(): void
    {
        $this->setUpCorpus();
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testEtiquettesCouleurEtBoiteDeReception(): void
    {
        $etiquettes = new Etiquettes($this->config);
        $tag = $etiquettes->ajouter('À faire', '#FF8800', true);
        self::assertSame('#FF8800', $tag['couleur']);
        self::assertTrue($tag['boite_reception']);
        self::assertSame($tag['id'], $etiquettes->boiteReception()['id'] ?? null);

        $etiquettes->definirCouleur($tag['id'], '#00aa55');
        $etiquettes->renommer($tag['id'], 'Urgent');
        $etiquettes->definirBoiteReception($tag['id'], false);
        $relu = $etiquettes->lire($tag['id']);
        self::assertSame('Urgent', $relu['nom']);
        self::assertSame('#00AA55', $relu['couleur'], 'la couleur est normalisée en majuscules');
        self::assertFalse($relu['boite_reception']);

        try {
            $etiquettes->ajouter('Bad', 'vert');
            self::fail('une couleur invalide devait être refusée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('couleur', $e->getMessage());
        }
    }

    public function testPreferencesParUtilisateurEtRefusDUnReglageInconnu(): void
    {
        $preferences = new Preferences($this->config);
        $preferences->enregistrer('olivia', ['mode_affichage' => 'small_cards', 'taille_page' => 25, 'colonnes' => ['title', 'tags']]);
        $relu = $preferences->lire('olivia');
        self::assertSame('small_cards', $relu['mode_affichage']);
        self::assertSame(25, $relu['taille_page']);
        self::assertSame(['title', 'tags'], $relu['colonnes']);

        $defauts = $preferences->lire('inconnu');
        self::assertSame('table', $defauts['mode_affichage'], 'un utilisateur sans réglage lit des défauts, jamais un trou');

        try {
            $preferences->enregistrer('olivia', ['reglage_inconnu' => 'x']);
            self::fail('un réglage inconnu devait être refusé');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('inconnu', $e->getMessage());
        }
    }

    public function testMatchingDelegueLAlgorithmeDeCorrespondance(): void
    {
        $matching = new Matching('Factures Atelier', 'facture atelier', Matching::ALGORITHMES[1]);
        self::assertSame('MATCH_ALL', $matching->algorithme);
        self::assertTrue($matching->correspond('FACTURE de l’Atelier X'), 'insensible à la casse par défaut');
        self::assertFalse($matching->correspond('Relevé bancaire'));

        $relu = Matching::depuisTableau($matching->versTableau());
        self::assertSame($matching->nom, $relu->nom);
        self::assertSame($matching->motif, $relu->motif);
        self::assertTrue($relu->insensible);

        try {
            new Matching('X', 'y', 'MATCH_INCONNU');
            self::fail('un algorithme inconnu devait être refusé');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('algorithme', $e->getMessage());
        }
    }

    public function testTachesCycleDeVieSansCelery(): void
    {
        $taches = new Taches($this->config);
        $tache = $taches->ouvrir('consume_file', 'manual_task', 'facture.pdf', 'olivia');
        self::assertSame('PENDING', $tache['status']);
        self::assertSame('CONSUME_FILE', $tache['task_name'], 'le nom canonique du modèle est conservé');
        self::assertFalse($tache['acknowledged']);
        self::assertNotSame('', $tache['task_id']);
        self::assertNotSame('', $tache['date_created']);

        $taches->demarrer($tache['id']);
        self::assertSame('STARTED', $taches->etat($tache['id'])['status']);
        self::assertNotNull($taches->etat($tache['id'])['date_started']);

        $taches->terminer($tache['id'], 'rangé sous 2026/Facture');
        $terminee = $taches->etat($tache['id']);
        self::assertSame('SUCCESS', $terminee['status']);
        self::assertSame('rangé sous 2026/Facture', $terminee['result']);
        self::assertNotNull($terminee['date_done']);

        $taches->accuser($tache['id']);
        self::assertTrue($taches->etat($tache['id'])['acknowledged']);

        $echouee = $taches->ouvrir('consume_file', 'AUTO', 'note.pdf');
        $taches->echouer($echouee['id'], 'PDF illisible');
        self::assertSame('FAILURE', $taches->etat($echouee['id'])['status']);
        self::assertSame('PDF illisible', $taches->etat($echouee['id'])['result']);

        $bilan = $taches->bilan();
        self::assertSame(2, $bilan['total']);
        self::assertSame(1, $bilan['terminees']);
        self::assertSame(1, $bilan['echouees']);
        self::assertSame(1, $bilan['non_acquittees']);
    }

    public function testChampUrlEtLienSontValides(): void
    {
        self::assertSame('https://exemple.test/a', Champs::validerValeur('url', [], 'https://exemple.test/a'));
        self::assertSame('tel:+1-816-555-1212', Champs::validerValeur('url', [], 'tel:+1-816-555-1212'));
        self::assertSame([7, 9], Champs::validerValeur('lien', [], [7, 9]));
        self::assertSame([12, 15], Champs::validerValeur('lien', [], '12,15'));

        foreach ([['url', 'pas un lien'], ['url', 'file:'], ['lien', 999], ['lien', ''], ['lien', []]] as [$nature, $valeur]) {
            try {
                Champs::validerValeur((string) $nature, [], $valeur);
                self::fail("la valeur devait être refusée ($nature)");
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testVuesReglagesAffichageTriEtTableauDeBord(): void
    {
        $vues = new Vues($this->config);
        $id = $vues->enregistrer('Tableau contrats', [
            'type' => 'Contrat', 'display_fields' => ['title', 'created', 'tags'], 'display_mode' => 'small_cards',
            'page_size' => 20, 'show_on_dashboard' => true, 'show_in_sidebar' => false,
            'sort_field' => 'created', 'sort_reverse' => true,
        ], 'olivia');

        $vue = $vues->lire($id);
        self::assertSame(['title', 'created', 'tags'], $vue['reglages']['affichage']);
        self::assertSame('small_cards', $vue['reglages']['mode']);
        self::assertSame(20, $vue['reglages']['taille_page']);
        self::assertTrue($vue['reglages']['tableau_bord']);
        self::assertFalse($vue['reglages']['barre_laterale']);
        self::assertSame('created', $vue['reglages']['tri']);
        self::assertTrue($vue['reglages']['tri_inverse']);

        self::assertSame([$id], array_column($vues->tableauDeBord(), 'id'));
        self::assertSame([], $vues->barreLaterale());

        $vues->modifierReglages($id, ['show_in_sidebar' => true, 'display_fields' => []], 'olivia');
        $maj = $vues->lire($id);
        self::assertTrue($maj['reglages']['barre_laterale']);
        self::assertNull($maj['reglages']['affichage'], 'une liste de champs vide vaut « aucun champ »');

        try {
            $vues->compilerReglages(['display_fields' => ['champ_inconnu']]);
            self::fail('un champ affiché inconnu devait être refusé');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('inconnu', $e->getMessage());
        }
        try {
            $vues->compilerReglages(['page_size' => 5000]);
            self::fail('une taille de page hors bornes devait être refusée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('taille', $e->getMessage());
        }
    }

    public function testModeleDroitsTraduitLesPermissionsPaperless(): void
    {
        self::assertSame('lire', ModeleDroits::action('view_document'));
        self::assertSame('modifier', ModeleDroits::action('change_document'));
        self::assertSame('gerer', ModeleDroits::action('delete_document'));
        self::assertSame('deposer', ModeleDroits::action('add_document'));
        self::assertContains('view_document', ModeleDroits::permissionsDuNiveau('lire'));

        try {
            ModeleDroits::action('permission_inconnue');
            self::fail('une permission inconnue devait être refusée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('inconnue', $e->getMessage());
        }
    }

    public function testPartageExterneVersionne(): void
    {
        $depot = new DepotFichiers($this->config);
        $id = $depot->deposer($this->pieceADeposer('contrat.pdf', 'contrat v2'), [
            'titre' => 'Contrat versionné', 'type' => 'Contrat', 'date' => '2026-09-01',
        ]);
        $collaboration = new Collaboration($this->config);

        $courant = $collaboration->partagerExterne($id, 'olivia', '2026-12-31');
        $lien = $collaboration->lienExterneValide($courant['jeton']);
        self::assertArrayHasKey('version', $lien);
        self::assertNull($lien['version'], 'un partage sans version vise la courante');

        $versionne = $collaboration->partagerExterne($id, 'olivia', '2026-12-31', 2);
        self::assertSame(2, $collaboration->lienExterneValide($versionne['jeton'])['version'] ?? null);
        $versions = array_map(static fn (array $p): int => (int) $p['version'], $collaboration->partages($id));
        self::assertContains(2, $versions, 'la version partagée est relue');

        try {
            $collaboration->partagerExterne($id, 'olivia', '2026-12-31', 0);
            self::fail('une version nulle devait être refusée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('version', $e->getMessage());
        }
    }
}
