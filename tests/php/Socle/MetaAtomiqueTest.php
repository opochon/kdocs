<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\DepotFichiers;
use Ged\Socle\Fichiers;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Oracle ged-meta-atomique (audit du 16-10, constat G1) : le `.meta.json` fait foi, son écriture ne doit
 * jamais être tronquée, silencieuse ni perdre une modification concurrente.
 *
 * - écriture par fichier temporaire, vidage disque puis renommage ; erreur nommée, aucun résidu ;
 * - lecture / transformation / écriture SOUS VERROU, relue depuis le disque (jamais depuis l'index) ;
 * - `mettreAJour` part du `.meta.json` du disque : une clé posée hors application survit ;
 * - un déplacement qui ne peut pas emmener le `.meta.json` échoue nommé et remet le document en place.
 */
final class MetaAtomiqueTest extends TestCase
{
    use SupportCorpus;

    private DepotFichiers $depot;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        $this->depot = new DepotFichiers($this->config);
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testEcrireMetaRemplaceToutLeContenuSansResidu(): void
    {
        $doc = $this->documentNu('remplace.pdf');
        file_put_contents("$doc.meta.json", str_repeat('ancien contenu long ', 200));

        Fichiers::ecrireMeta($doc, Fichiers::json(['titre' => 'Nouveau'], true));

        self::assertSame(['titre' => 'Nouveau'], json_decode((string) file_get_contents("$doc.meta.json"), true),
            'le remplacement est complet, même sur Windows où la cible existe déjà');
        self::assertSame([], $this->residus(dirname($doc)), 'aucun fichier temporaire ne reste');
    }

    public function testEcrireMetaEchoueNommeSiLaPublicationEstImpossible(): void
    {
        $doc = $this->documentNu('bloque.pdf');
        mkdir("$doc.meta.json");   // un dossier à la place du fichier : le renommage ne peut pas aboutir

        try {
            Fichiers::ecrireMeta($doc, '{}');
            self::fail('une publication impossible doit lever, jamais être avalée');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('meta', $e->getMessage());
        }
        self::assertSame([], $this->residus(dirname($doc)), 'le temporaire est retiré après l’échec');
    }

    public function testEcrireMetaEchoueNommeSiLeDossierNExistePas(): void
    {
        $this->expectException(RuntimeException::class);
        Fichiers::ecrireMeta($this->racine . '/fonds/absent/inexistant.pdf', '{}');
    }

    public function testUneTransformationQuiLeveNEcritRien(): void
    {
        $doc = $this->documentNu('intact.pdf');
        file_put_contents("$doc.meta.json", Fichiers::json(['titre' => 'Intact'], true));

        try {
            Fichiers::modifierMeta($doc, static function (array $meta): array {
                throw new RuntimeException('refus métier');
            });
            self::fail('la transformation devait lever');
        } catch (RuntimeException $e) {
            self::assertSame('refus métier', $e->getMessage());
        }
        self::assertSame(['titre' => 'Intact'], json_decode((string) file_get_contents("$doc.meta.json"), true));
        self::assertSame([], $this->residus(dirname($doc)));
    }

    public function testModifierMetaRefuseUnDocumentDisparu(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/introuvable|déplacé/');
        Fichiers::modifierMeta($this->racine . '/fonds/parti.pdf', static fn (array $m): array => $m);
    }

    public function testDeuxProcessusConcurrentsNePerdentAucuneModification(): void
    {
        $doc = $this->documentNu('compteur.pdf');
        file_put_contents("$doc.meta.json", Fichiers::json(['compteur' => 0], true));
        $script = __DIR__ . '/Support/incrementer-meta.php';

        $processus = [];
        foreach (['a', 'b', 'c'] as $nom) {
            $processus[$nom] = proc_open([PHP_BINARY, $script, $doc, '40', $nom], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
            $processus[$nom . '-tubes'] = $tubes;
        }
        foreach (['a', 'b', 'c'] as $nom) {
            $sortie = stream_get_contents($processus[$nom . '-tubes'][1]) . stream_get_contents($processus[$nom . '-tubes'][2]);
            self::assertSame(0, proc_close($processus[$nom]), "processus $nom : $sortie");
        }

        $meta = json_decode((string) file_get_contents("$doc.meta.json"), true);
        self::assertSame(120, $meta['compteur'], 'trois processus x 40 incréments : aucune mise à jour perdue');
        self::assertCount(40, $meta['a']);
        self::assertCount(40, $meta['b']);
        self::assertCount(40, $meta['c']);
    }

    public function testMettreAJourPartDuDisqueEtGardeUneClePoseeHorsApplication(): void
    {
        $id = $this->depot->deposer($this->pieceADeposer('lettre.pdf', 'lettre'), [
            'titre' => 'Lettre', 'type' => 'courrier', 'date' => '2026-03-02',
        ]);
        $rel = (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();
        $absolu = $this->racine . '/fonds/' . $rel;

        // Le dossier fait foi : une main (ou un secteur) pose une clé sur le disque, l'index ne l'a pas encore vue.
        $disque = json_decode((string) file_get_contents("$absolu.meta.json"), true);
        $disque['note_manuelle'] = 'posée hors application';
        file_put_contents("$absolu.meta.json", Fichiers::json($disque, true));

        $this->depot->mettreAJour($id, ['emetteur' => 'Banque Exemple'], 'oracle');

        $relApres = (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();
        $meta = $this->meta($relApres);
        self::assertSame('Banque Exemple', $meta['emetteur']);
        self::assertSame('posée hors application', $meta['note_manuelle'] ?? null,
            'la mise à jour fusionne dans le .meta.json du disque, jamais dans la copie de l’index');
        self::assertSame([], $this->residus(dirname($this->racine . '/fonds/' . $relApres)));
    }

    public function testDeplacementSansMetaPossibleEchoueEtRemetLeDocument(): void
    {
        $avant = $this->documentNu('a-deplacer.pdf');
        file_put_contents("$avant.meta.json", Fichiers::json(['titre' => 'A'], true));
        $dossierApres = $this->racine . '/fonds/arrivee';
        mkdir($dossierApres, 0775, true);
        mkdir($dossierApres . '/a-deplacer.pdf.meta.json');   // le .meta.json ne pourra pas suivre

        try {
            Fichiers::deplacerAvecAnnexes($avant, $dossierApres . '/a-deplacer.pdf');
            self::fail('un .meta.json qui ne suit pas doit lever');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('meta', $e->getMessage());
        }
        self::assertFileExists($avant, 'le document est remis à sa place');
        self::assertFileExists("$avant.meta.json", 'son .meta.json n’a pas bougé');
    }

    private function documentNu(string $nom): string
    {
        $dossier = $this->racine . '/fonds/essais';
        if (!is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }
        file_put_contents("$dossier/$nom", 'contenu');

        return "$dossier/$nom";
    }

    /** @return list<string> */
    private function residus(string $dossier): array
    {
        return array_values(array_filter(scandir($dossier) ?: [], static fn (string $n): bool => str_ends_with($n, '.tmp')));
    }
}
