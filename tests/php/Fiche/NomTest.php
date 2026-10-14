<?php

declare(strict_types=1);

namespace Ged\Tests\Fiche;

use Ged\Fiche\Nom;
use Ged\Socle\DepotFichiers;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du TITRE DE FICHIER (nom du fichier) — équivalent GEDv2 de
 * `GET|PUT /objects/{type}/{objectid}/{version}/files/{file}/title` : le nom se lit, se renomme, et le
 * renommage est un acte réel (fichier, `.meta.json` et `.versions/` suivent, index et trace à jour),
 * jamais une suppression et jamais un titre d'objet modifié.
 */
final class NomTest extends TestCase
{
    use SupportFiche;

    protected function setUp(): void
    {
        $this->setUpCorpus();
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testLeNomDeFichierSeLitSurLIndex(): void
    {
        $id = $this->deposerFiche('facture.txt', 'Facture 2026', 'contenu facture');

        $lu = (new Nom($this->config))->lire($id);

        self::assertSame('Facture_2026.txt', $lu['nom'], 'le nom rangé suit le titre assaini');
        self::assertSame('2026/Facture/Atelier_Fictif_SA/Facture_2026.txt', $lu['chemin']);
    }

    public function testRenommerLeFichierDeplaceMetaEtVersionsEtTraceSansRienPerdre(): void
    {
        $depot = new DepotFichiers($this->config);
        $id = $this->deposerFiche('facture.txt', 'Facture 2026', 'contenu facture');
        $avant = $this->metaLigne($id);
        $octets = $this->contenu($avant['chemin']);

        $nouveau = (new Nom($this->config))->renommer($id, 'Facture 2026 renommee.txt', 'alice@exemple.test');

        self::assertSame('Facture_2026_renommee.txt', $nouveau);
        $apres = $this->metaLigne($id);
        self::assertSame('Facture_2026_renommee.txt', $apres['nom']);
        self::assertNotSame($avant['chemin'], $apres['chemin']);
        self::assertStringEndsWith('/Facture_2026_renommee.txt', $apres['chemin']);
        // Le contenu et le dossier annexe suivent le fichier : aucune version perdue.
        self::assertSame($octets, $this->contenu($apres['chemin']));
        self::assertFileExists($this->racine . '/fonds/' . $apres['chemin'] . '.meta.json');
        self::assertDirectoryExists(dirname($this->racine . '/fonds/' . $apres['chemin']) . '/.versions/Facture_2026_renommee.txt');
        // Le titre de l'objet ne change pas : renommer le fichier n'est pas retitrer l'objet.
        self::assertSame('Facture 2026', json_decode((string) file_get_contents($this->racine . '/fonds/' . $apres['chemin'] . '.meta.json'), true)['titre']);
        // Le geste est tracé comme un déplacement réel.
        $trace = $this->pdo->query('SELECT cause FROM document_deplacements WHERE document_id = ' . $id . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
        self::assertSame('renommage', $trace);
        // L'ancien fichier n'existe plus (le renommage est un rename, jamais une copie).
        self::assertFileDoesNotExist($this->racine . '/fonds/' . $avant['chemin']);
    }

    public function testUnNomVideEstRefuseEtNeRenommeRien(): void
    {
        $id = $this->deposerFiche('facture.txt', 'Facture 2026', 'contenu facture');

        try {
            (new Nom($this->config))->renommer($id, '   ', 'alice@exemple.test');
            self::fail('un nom vide doit être refusé');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('nom', $e->getMessage());
        }

        self::assertSame('Facture_2026.txt', (new Nom($this->config))->lire($id)['nom']);
    }

    /** @return array{chemin:string, nom:string} */
    private function metaLigne(int $id): array
    {
        $ligne = $this->pdo->query('SELECT chemin_relatif, nom FROM documents WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);

        return ['chemin' => (string) $ligne['chemin_relatif'], 'nom' => (string) $ligne['nom']];
    }

    private function contenu(string $cheminRelatif): string
    {
        return (string) file_get_contents($this->racine . '/fonds/' . $cheminRelatif);
    }
}
