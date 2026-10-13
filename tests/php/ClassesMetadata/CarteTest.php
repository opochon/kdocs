<?php

declare(strict_types=1);

namespace Ged\Tests\ClassesMetadata;

use Ged\ClassesMetadata\Carte;
use Ged\Socle\Champs;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Listes;
use Ged\Socle\Nomenclature;
use PHPUnit\Framework\TestCase;

/**
 * Famille M-Files nº 1 (lot ged-mfiles-classes-1) : la CARTE DE MÉTADONNÉES est dynamique selon la
 * CLASSE du document. Un objet porte une classe (le type de la nomenclature) qui détermine les
 * propriétés affichées/saisies ; les valeurs vivent dans le `.meta.json` (le dossier fait foi) et la
 * validation contrôle le type, l'obligation et l'appartenance à une liste nommée.
 */
final class CarteTest extends TestCase
{
    use SupportClassesMetadata;

    private Carte $carte;
    private Champs $champs;
    private DepotFichiers $depot;

    protected function setUp(): void
    {
        $this->setUpClassesMetadata();
        (new Nomenclature($this->config))->assurer();
        (new Listes($this->config))->assurer();
        $this->carte = new Carte($this->config);
        $this->champs = new Champs($this->config);
        $this->depot = new DepotFichiers($this->config);
    }

    protected function tearDown(): void
    {
        $this->tearDownClassesMetadata();
    }

    private function idDuType(string $nom): int
    {
        foreach ((new Nomenclature($this->config))->lister() as $t) {
            if ($t['nom'] === $nom) {
                return (int) $t['id'];
            }
        }
        self::fail("type « $nom » absent");
    }

    /** Un document de la classe demandée, rangé dans le fonds ; rend son chemin relatif. */
    private function deposer(string $type, string $nomFichier): string
    {
        $id = $this->depot->deposer($this->pieceADeposer($nomFichier, 'contenu ' . $nomFichier), [
            'titre' => 'Pièce ' . $nomFichier, 'type' => $type, 'date' => '2026-09-01',
        ]);

        return (string) $this->depot->fiche($id)['chemin_relatif'];
    }

    public function testLaCarteDuneClasseNeMontreQueSesPropresProprietes(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance', 'date', [], true);
        $this->champs->ajouter($this->idDuType('Facture'), 'Montant HT', 'montant');

        $contrat = $this->carte->pourClasse('Contrat');
        self::assertSame('Contrat', $contrat['classe']['nom']);
        self::assertSame(['echeance'], array_column($contrat['proprietes'], 'cle'),
            'la carte de la classe Contrat ne porte que ses propriétés');
        self::assertTrue($contrat['proprietes'][0]['obligatoire']);

        $facture = $this->carte->pourClasse('Facture');
        self::assertSame(['montant-ht'], array_column($facture['proprietes'], 'cle'));
        self::assertFalse($facture['proprietes'][0]['obligatoire']);
    }

    public function testLaCarteDunDocumentSuitSaClasseEtRelitSesValeurs(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Statut', 'liste', [], false, 'statut');
        $chemin = $this->deposer('Contrat', 'contrat.pdf');
        $this->carte->poser($chemin, 'statut', 'Validé');

        $carte = $this->carte->pourDocument($chemin);
        self::assertSame('Contrat', $carte['classe']);
        self::assertSame('Validé', $carte['proprietes'][0]['valeur']);
        self::assertSame((new Listes($this->config))->valeursDe('statut'), $carte['proprietes'][0]['options']);
        self::assertSame('Validé', $this->carte->valeur($chemin, 'statut'));
    }

    public function testValiderControleObligationTypeEtListeNommee(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance', 'date', [], true);
        $this->champs->ajouter($this->idDuType('Contrat'), 'Statut', 'liste', [], false, 'statut');

        self::assertSame(['echeance' => '2026-12-24', 'statut' => 'Validé'],
            $this->carte->valider('Contrat', ['echeance' => '2026-12-24', 'statut' => 'Validé']));

        try {
            $this->carte->valider('Contrat', ['statut' => 'Validé']);
            self::fail('une propriété obligatoire vide devait être refusée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('obligatoire', $e->getMessage());
        }
        try {
            $this->carte->valider('Contrat', ['echeance' => 'bientôt', 'statut' => 'Validé']);
            self::fail('une date invalide devait être refusée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('date', $e->getMessage());
        }
        try {
            $this->carte->valider('Contrat', ['echeance' => '2026-12-24', 'statut' => 'Inventé']);
            self::fail('une valeur hors liste nommée devait être refusée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('statut', $e->getMessage());
        }
    }

    public function testPoserRelireEtEffacerUneValeurTypeeSansToucherAuFichier(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Renouvelé', 'booleen');
        $this->champs->ajouter($this->idDuType('Contrat'), 'Forfait', 'montant');
        $chemin = $this->deposer('Contrat', 'contrat-bail.pdf');

        $this->carte->poser($chemin, 'renouvele', true);
        $this->carte->poser($chemin, 'forfait', 1234.5);
        self::assertTrue($this->meta($chemin)['champs']['renouvele']);
        self::assertSame(1234.5, $this->meta($chemin)['champs']['forfait']);

        self::assertFileExists($this->racine . '/fonds/' . $chemin);
        $this->carte->effacer($chemin, 'renouvele');
        self::assertArrayNotHasKey('renouvele', $this->meta($chemin)['champs'] ?? []);
        self::assertFileExists($this->racine . '/fonds/' . $chemin, 'effacer un champ ne touche jamais au fichier');
    }

    public function testPoserPlusieursEtSurPlusieursDocuments(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Statut', 'liste', [], false, 'statut');
        $a = $this->deposer('Contrat', 'contrat-a.pdf');
        $b = $this->deposer('Contrat', 'contrat-b.pdf');

        $this->carte->poserPlusieurs($a, ['statut' => 'En cours']);
        self::assertSame('En cours', $this->carte->valeur($a, 'statut'));

        $posees = $this->carte->poserSurPlusieurs([$a, $b], ['statut' => 'Validé']);
        self::assertCount(2, $posees);
        self::assertSame('Validé', $this->carte->valeur($a, 'statut'));
        self::assertSame('Validé', $this->carte->valeur($b, 'statut'));

        $cartes = $this->carte->pourDocuments([$a, $b]);
        self::assertCount(2, $cartes);
        self::assertSame('Validé', $cartes[$b]['valeurs']['statut']);
    }

    public function testResoudreLesAliasDeLaStructure(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance', 'date');

        self::assertSame($this->idDuType('Contrat'), $this->carte->classeParAlias('contrat')['id'] ?? null);
        self::assertNull($this->carte->classeParAlias('classe-inconnue'));

        $alias = $this->carte->classesParAlias(['contrat', 'facture']);
        self::assertSame($this->idDuType('Facture'), $alias['facture']);

        $propriete = $this->carte->resoudreAlias('echeance');
        self::assertSame('propriete', $propriete['type'] ?? null);
        $liste = $this->carte->resoudreAlias('statut');
        self::assertSame('liste', $liste['type'] ?? null);
        $classe = $this->carte->resoudreAlias('contrat');
        self::assertSame('classe', $classe['type'] ?? null);
        self::assertNull($this->carte->resoudreAlias('rien-du-tout'));
    }

    public function testValeursDeProprieteViennentDeLaListeNommee(): void
    {
        $champ = $this->champs->ajouter($this->idDuType('Contrat'), 'Statut', 'liste', [], false, 'statut');
        $details = $this->carte->valeursDePropriete($champ['id']);
        self::assertSame('statut', $details['liste']);
        self::assertSame((new Listes($this->config))->valeursDe('statut'), $details['valeurs']);

        $inline = $this->champs->ajouter($this->idDuType('Contrat'), 'Service', 'liste', ['Audit', 'Révision']);
        self::assertSame(['Audit', 'Révision'], $this->carte->valeursDePropriete($inline['id'])['valeurs']);
    }

    public function testDocumentsDeClasseNeRendQueLesVivantsDeCetteClasse(): void
    {
        $this->deposer('Contrat', 'contrat-1.pdf');
        $this->deposer('Contrat', 'contrat-2.pdf');
        $this->deposer('Facture', 'facture-1.pdf');

        $contrats = $this->carte->documentsDeClasse('Contrat');
        self::assertCount(2, $contrats);
        self::assertSame(['Contrat', 'Contrat'], array_column($contrats, 'type'));
    }
}
