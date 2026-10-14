<?php

declare(strict_types=1);

namespace Ged\Tests\Fiche;

use Ged\Socle\DepotFichiers;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du TITRE D'OBJET — équivalent GEDv2 de `GET|PUT /objects/{type}/{objectid}/{version}/title`.
 * Le titre canonique vit dans le `.meta.json` à côté du fichier (le dossier fait foi), la colonne
 * `titre` n'en est que la copie d'index. Changer le titre de l'objet NE renomme PAS le fichier et ne
 * crée aucune version : seules les métadonnées bougent.
 */
final class TitreTest extends TestCase
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

    public function testLeTitreDeLObjetSeLitDuMetaJsonQuiFaitFoi(): void
    {
        $id = $this->deposerFiche('facture.txt', 'Facture 2026', 'contenu');
        $ligne = (new DepotFichiers($this->config))->fiche($id);

        self::assertSame('Facture 2026', $ligne['meta']['titre']);
        $aCote = json_decode((string) file_get_contents($this->racine . '/fonds/' . $ligne['chemin_relatif'] . '.meta.json'), true);
        self::assertSame('Facture 2026', $aCote['titre']);
    }

    public function testChangerLeTitreMetAJourLeMetaEtLIndexSansRenommerLeFichierNiCreerDeVersion(): void
    {
        $depot = new DepotFichiers($this->config);
        $id = $this->deposerFiche('facture.txt', 'Facture 2026', 'contenu');
        $avant = $depot->fiche($id);
        $versionsAvant = (int) $this->pdo->query('SELECT COUNT(*) FROM document_versions WHERE document_id = ' . $id)->fetchColumn();

        $depot->mettreAJour($id, ['titre' => 'Facture 2026 corrigée'], 'alice@exemple.test');

        $apres = $depot->fiche($id);
        self::assertSame('Facture 2026 corrigée', $apres['meta']['titre']);
        self::assertSame('Facture 2026 corrigée', $apres['titre']);
        self::assertSame($avant['chemin_relatif'], $apres['chemin_relatif'], 'changer un titre ne déplace jamais un document');
        self::assertSame($avant['nom'], $apres['nom'], 'le nom du fichier est fixé au dépôt : il ne suit pas le titre');
        self::assertSame($versionsAvant, (int) $this->pdo->query('SELECT COUNT(*) FROM document_versions WHERE document_id = ' . $id)->fetchColumn());
    }
}
