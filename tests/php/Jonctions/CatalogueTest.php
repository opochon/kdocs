<?php

declare(strict_types=1);

namespace Ged\Tests\Jonctions;

use Ged\Jonctions\Catalogue;
use Ged\Jonctions\ErreurApi;
use Ged\Socle\DepotFichiers;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du SERVICE du lot ged-api-documents : la liste et la recherche portent le périmètre des droits
 * DANS la requête (`DroitsDossiers::predicatLecture`), la pagination est bornée, la fiche et le fichier
 * refusent un document hors dossiers, et la modification écrit le `.meta.json`.
 */
final class CatalogueTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->tearDownJonctions();
    }

    public function testListeRechercheEtReferentielsRespectentLesDroits(): void
    {
        $donnees = $this->compteEtDossiers();
        $visible = $this->deposerDans($donnees['ecriture'], 'Facture lisible', 'lisible-' . $this->sel, 'Visible SA');
        $cache = $this->deposerDans($donnees['refus'], 'Facture secrete', 'secrete-' . $this->sel, 'Cachee SA');
        $catalogue = new Catalogue($this->config(), $donnees['compte'], false);

        $ids = array_column($catalogue->lister([])['documents'], 'id');
        self::assertContains($visible, $ids);
        self::assertNotContains($cache, $ids, 'la liste ne rend jamais un document hors dossiers');

        $ids = array_column($catalogue->lister(['q' => 'Facture'])['documents'], 'id');
        self::assertContains($visible, $ids);
        self::assertNotContains($cache, $ids, 'la recherche ne rend jamais un document hors dossiers');

        self::assertContains('Visible SA', $catalogue->emetteurs());
        self::assertNotContains('Cachee SA', $catalogue->emetteurs());
    }

    public function testPaginationBornee(): void
    {
        $donnees = $this->compteEtDossiers();
        foreach (['Un', 'Deux', 'Trois'] as $titre) {
            $this->deposerDans($donnees['ecriture'], $titre, $titre . '-' . $this->sel, 'E');
        }
        $catalogue = new Catalogue($this->config(), $donnees['compte'], false);

        $page1 = $catalogue->lister(['taille' => 2, 'page' => 1]);
        self::assertSame(3, $page1['total']);
        self::assertSame(2, $page1['taille']);
        self::assertSame(2, $page1['pages']);
        self::assertCount(2, $page1['documents']);

        $borne = $catalogue->lister(['taille' => 10000, 'page' => 0]);
        self::assertSame(100, $borne['taille'], 'la taille de page est bornée');
        self::assertSame(1, $borne['page'], 'la page est bornée à 1');
    }

    public function testFicheFichierEtVersionRefusentHorsDroits(): void
    {
        $donnees = $this->compteEtDossiers();
        $visible = $this->deposerDans($donnees['ecriture'], 'Piece visible', 'contenu-visible-' . $this->sel, 'E');
        $cache = $this->deposerDans($donnees['refus'], 'Piece cachee', 'contenu-secret-' . $this->sel, 'E');
        $catalogue = new Catalogue($this->config(), $donnees['compte'], false);

        self::assertSame('Piece visible', $catalogue->fiche($visible, 'test')['titre']);
        self::assertSame('contenu-visible-' . $this->sel, (string) file_get_contents($catalogue->fichier($visible, null, 'test')['chemin']));

        try {
            $catalogue->fiche($cache, 'test');
            self::fail('une fiche hors droits devait être refusée');
        } catch (ErreurApi $e) {
            self::assertSame(403, $e->statut);
        }
        try {
            $catalogue->fichier($cache, null, 'test');
            self::fail('un téléchargement hors droits devait être refusé');
        } catch (ErreurApi $e) {
            self::assertSame(403, $e->statut);
        }
    }

    public function testModificationEcritLeMetaJsonEtLeDossier(): void
    {
        $donnees = $this->compteEtDossiers();
        $id = $this->deposerDans($donnees['ecriture'], 'Titre initial', 'meta-' . $this->sel, 'E');
        $catalogue = new Catalogue($this->config(), $donnees['compte'], false);

        $catalogue->modifier($id, ['titre' => 'Titre changé', 'etiquettes' => ['A', 'B']], 'test', 'test');

        self::assertSame('Titre changé', $catalogue->fiche($id, 'test')['titre']);
        $chemin = (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();
        $meta = json_decode((string) file_get_contents($this->fonds . '/' . $chemin . '.meta.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Titre changé', $meta['titre']);
        self::assertSame(['A', 'B'], $meta['etiquettes']);
    }

    private function deposerDans(string $dossier, string $titre, string $contenu, string $emetteur = ''): int
    {
        $chemin = $this->piece($titre . '.pdf', $contenu);
        $meta = ['titre' => $titre, 'dossier' => $dossier];
        if ($emetteur !== '') {
            $meta['emetteur'] = $emetteur;
        }

        return (new DepotFichiers($this->config()))->deposer($chemin, $meta);
    }
}
