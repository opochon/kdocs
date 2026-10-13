<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\DepotFichiers;
use Ged\Socle\Registre;
use Ged\Socle\Relations;
use PHPUnit\Framework\TestCase;

/**
 * Famille M-Files nº 2 (lot ged-mfiles-relations-1) : les RELATIONS entre objets sont de la
 * métadonnée TYPÉE. Les définitions vivent dans le fonds (`.ged/relations.json`) ; les valeurs vivent
 * dans le `.meta.json` à côté de chaque fichier. Un lien se navigue dans les DEUX sens ; la composition
 * parent-enfant s'appuie sur le système de fichiers et le complète ; une référence vers un objet
 * déplacé se retrouve par empreinte et une référence vers un objet supprimé reste traçable.
 *
 * Le dossier fait foi : aucune table nouvelle, aucune donnée effacée.
 */
final class RelationsTest extends TestCase
{
    use SupportCorpus;

    private Relations $relations;
    private DepotFichiers $depot;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        $this->relations = new Relations($this->config);
        $this->depot = new DepotFichiers($this->config);
        $this->relations->assurer();
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testLaGraineDesTypesVientDeLaDocumentationPubliqueEtVitDansLeFonds(): void
    {
        $noms = array_column($this->relations->types(), 'nom');
        self::assertSame(['Référence', 'Dépendance', 'Affectation', 'Sous-objet'], $noms,
            'les types publiés par la documentation M-Files');
        $natures = array_column($this->relations->types(), 'nature');
        self::assertSame(['reference', 'dependance', 'affectation', 'sous-objet'], $natures);

        $fichier = $this->racine . '/fonds/.ged/relations.json';
        self::assertFileExists($fichier, 'les définitions de relations vivent dans le fonds, pas en base');
        $surDisque = json_decode((string) file_get_contents($fichier), true) ?: [];
        self::assertCount(4, $surDisque['types'] ?? [], 'le fichier porte la définition : le dossier fait foi');

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotContains('socle_relations', $tables, 'aucune table nouvelle : le plafond de 20 tient');
    }

    public function testUnLienTypeEstNavigableDansLesDeuxSens(): void
    {
        [$aId, $a] = $this->deposer('Facture A', 'Facture');
        [, $b] = $this->deposer('Contrat B', 'Contrat');

        $this->relations->lier($a, $b, 'reference', 'alice@gedv2.test');

        $carteA = $this->relations->pourDocument($a);
        $carteB = $this->relations->pourDocument($b);

        self::assertCount(1, $carteA, 'la fiche source voit son lien sortant');
        self::assertCount(1, $carteB, 'la fiche cible voit le lien entrant : navigable dans les deux sens');
        self::assertSame('sortante', $carteA[0]['sens']);
        self::assertSame('entrante', $carteB[0]['sens']);
        self::assertSame('reference', $carteA[0]['type']);
        self::assertSame('Référence', $carteA[0]['type_nom']);
        self::assertSame($b, $carteA[0]['cible'], 'le lien nomme l’objet lié');
        self::assertSame('vivant', $carteA[0]['etat'], 'la cible existe : le lien est vivant');
        self::assertSame($a, $carteB[0]['cible'], 'la fiche cible nomme la source');
        self::assertSame($aId, (int) $this->depot->fiche($aId)['id'], 'le document reste rangé');
    }

    public function testCompterLesRelationsDesDeuxSens(): void
    {
        [, $a] = $this->deposer('Facture A', 'Facture');
        [, $b] = $this->deposer('Contrat B', 'Contrat');
        [, $c] = $this->deposer('Reçu C', 'Reçu');

        $this->relations->lier($a, $b, 'reference');
        $this->relations->lier($a, $c, 'dependance');

        self::assertSame(2, $this->relations->compter($a), 'deux relations sortantes');
        self::assertSame(1, $this->relations->compter($b), 'la cible compte son lien entrant');
        self::assertSame(1, $this->relations->compter($c));
    }

    public function testRetirerUneRelationLaisseUneTraceSansPerdreLeLien(): void
    {
        [, $a] = $this->deposer('Facture A', 'Facture');
        [, $b] = $this->deposer('Contrat B', 'Contrat');
        $id = $this->relations->lier($a, $b, 'reference');

        $this->relations->retirer($a, $id, 'bob@gedv2.test');

        self::assertCount(0, $this->relations->pourDocument($a), 'le lien retiré n’est plus actif');
        $meta = $this->meta($a);
        $relique = null;
        foreach ((array) ($meta['relations'] ?? []) as $entree) {
            if ((int) ($entree['id'] ?? 0) === $id) {
                $relique = $entree;
            }
        }
        self::assertNotNull($relique, 'la relation retirée reste dans le dossier : rien n’est effacé');
        self::assertFalse($relique['actif']);
        self::assertNotEmpty($relique['retire_le'], 'le retrait est daté');
        self::assertSame('bob@gedv2.test', $relique['retire_par'], 'le retrait est attribué');
        self::assertSame($b, $relique['cible'], 'la trace garde la cible');
        self::assertNotEmpty($this->relations->journal($a), 'le journal des relations trace le retrait');
    }

    public function testUnLienRefuseSoiMemeUnDoublonEtUnTypeInconnu(): void
    {
        [, $a] = $this->deposer('Facture A', 'Facture');
        [, $b] = $this->deposer('Contrat B', 'Contrat');

        foreach ([
            'distinct' => fn () => $this->relations->lier($a, $a, 'reference'),
            'inconnu' => fn () => $this->relations->lier($a, $b, 'inventee'),
        ] as $attendu => $geste) {
            try {
                $geste();
                self::fail("le lien ($attendu) devait être refusé");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($attendu, $e->getMessage(), 'le refus se nomme');
            }
        }

        $this->relations->lier($a, $b, 'reference');
        try {
            $this->relations->lier($a, $b, 'reference');
            self::fail('un doublon devait être refusé');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('existe', $e->getMessage());
        }
        self::assertSame(1, $this->relations->compter($a));
    }

    public function testCompositionParentEnfantEtDetachement(): void
    {
        [, $dossier] = $this->deposer('Dossier client', 'Courrier');
        [, $piece] = $this->deposer('Pièce A', 'Facture');
        [, $autre] = $this->deposer('Pièce B', 'Reçu');

        $this->relations->lier($dossier, $piece, 'sous-objet');
        $this->relations->lier($dossier, $autre, 'sous-objet');

        $enfants = $this->relations->enfants($dossier);
        self::assertCount(2, $enfants, 'le dossier contient deux pièces');
        self::assertSame(2, $this->relations->compterEnfants($dossier));
        $parents = $this->relations->parents($piece);
        self::assertCount(1, $parents, 'la pièce appartient à son dossier');
        self::assertSame($dossier, $parents[0]['chemin'], 'le parent est retrouvé depuis l’enfant');

        $detaches = $this->relations->detacher($dossier, $piece, 'alice@gedv2.test');
        self::assertSame(1, $detaches, 'détacher rend la pièce indépendante');
        self::assertCount(1, $this->relations->enfants($dossier), 'il reste la seconde pièce');
        self::assertCount(0, $this->relations->parents($piece), 'la pièce n’appartient plus au dossier');
        self::assertNotEmpty($this->relations->journal($piece), 'le détachement est tracé');
    }

    public function testLaReferenceVersUnObjetDeplaceResteTracable(): void
    {
        [$cibleId, $cible] = $this->deposer('Contrat B', 'Facture');
        [, $a] = $this->deposer('Facture A', 'Facture');
        $this->relations->lier($a, $cible, 'reference');

        // Déplacement géré par le produit (changement de rangement) : le chemin change, le contenu non.
        $this->depot->mettreAJour($cibleId, ['type' => 'Contrat'], 'alice@gedv2.test');
        $nouveau = (string) $this->depot->fiche($cibleId)['chemin_relatif'];
        self::assertNotSame($cible, $nouveau, 'le document a bien été déplacé');

        $carte = $this->relations->pourDocument($a);
        self::assertCount(1, $carte, 'le lien n’est pas perdu par le déplacement');
        self::assertSame('deplace', $carte[0]['etat'], 'la référence retrouve l’objet par son empreinte');
        self::assertSame($nouveau, $carte[0]['cible'], 'le lien est réécrit vers la nouvelle place');
    }

    public function testLaReferenceVersUnObjetALaCorbeilleResteTracable(): void
    {
        [$cibleId, $cible] = $this->deposer('Contrat B', 'Contrat');
        [, $a] = $this->deposer('Facture A', 'Facture');
        $this->relations->lier($a, $cible, 'reference');

        $this->depot->miseALaCorbeille($cibleId, 'alice@gedv2.test');

        $carte = $this->relations->pourDocument($a);
        self::assertCount(1, $carte, 'un lien vers un objet supprimé n’est pas effacé en silence');
        self::assertSame('corbeille', $carte[0]['etat'], 'la référence dit que la cible est à la corbeille');
        self::assertNotEmpty($carte[0]['cible_titre'], 'le dernier titre connu reste lisible');
        self::assertNotEmpty($carte[0]['empreinte'], 'la dernière empreinte connue reste lisible');
    }

    public function testLaHierarchieSAppuieSurLeFsEtNeLeDupliquePas(): void
    {
        [, $a] = $this->deposer('Facture A', 'Facture');
        [, $b] = $this->deposer('Reçu B', 'Facture');
        // Les deux documents sont rangés dans le même dossier de type.
        self::assertSame(dirname($a), dirname($b));

        $hierarchie = $this->relations->hierarchie($a);
        self::assertSame(dirname($a), $hierarchie['dossier'], 'le contenant se lit du chemin (le dossier fait foi)');
        $pieces = array_column($hierarchie['pieces'], 'chemin');
        self::assertContains($b, $pieces, 'le dossier physique contient les pièces voisines');
        self::assertNotContains($a, $pieces, 'le document n’est pas sa propre pièce');
        self::assertSame([], $hierarchie['enfants'], 'aucune relation de composition inventée');

        // Une composition explicite COMPLÈTE le FS sans être stockée comme un dossier.
        [, $dossier] = $this->deposer('Dossier client', 'Courrier');
        $this->relations->lier($dossier, $a, 'sous-objet');
        $hierarchie = $this->relations->hierarchie($dossier);
        self::assertSame([$a], array_column($hierarchie['enfants'], 'chemin'), 'la relation complète le FS');
        self::assertSame(dirname($dossier), $hierarchie['dossier']);
    }

    public function testDefinitionPortableEtReversible(): void
    {
        [, $a] = $this->deposer('Facture A', 'Facture');
        [, $b] = $this->deposer('Contrat B', 'Contrat');
        $this->relations->lier($a, $b, 'reference');

        $definition = $this->relations->exporter();
        self::assertCount(4, $definition['types']);

        @unlink($this->racine . '/fonds/.ged/relations.json');
        Registre::oublier();
        $this->relations->importer($definition);
        $relu = $this->relations->exporter();
        self::assertSame($definition, $relu, 'export → import → export rend la même définition');

        // Les VALEURS vivent dans le .meta.json : elles survivent à la reconstruction de la définition.
        self::assertCount(1, $this->relations->pourDocument($a), 'les valeurs restent portées par le dossier');
    }

    /**
     * Dépose un document du fonds ; rend [identifiant, chemin relatif].
     *
     * @return array{0:int, 1:string}
     */
    private function deposer(string $titre, string $type): array
    {
        $nom = strtolower(str_replace(' ', '-', $titre)) . '-' . bin2hex(random_bytes(3)) . '.pdf';
        $id = $this->depot->deposer($this->pieceADeposer($nom, 'contenu ' . $titre), [
            'titre' => $titre, 'type' => $type, 'date' => '2026-09-01',
        ]);

        return [$id, (string) $this->depot->fiche($id)['chemin_relatif']];
    }
}
