<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Socle\DepotFichiers;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Vues d'information et de recherche de l'API (famille 3 du lot ged-parite-api-2).
 *
 * Correspondances fonctionnelles avec Paperless-ngx 2.20.5 (src/documents/views.py) :
 *   - StatisticsView          -> GET  /api/v1/statistiques (compteurs au périmètre des droits) ;
 *   - UnifiedSearchViewSet.next_asn -> GET /api/v1/next_asn (numéro d'archive suivant) ;
 *   - SearchAutoCompleteView  -> GET  /api/v1/autocomplete (suggestions de titres) ;
 *   - SelectionDataView       -> POST /api/v1/documents/selection_data (comptes par référentiel) ;
 *   - DocumentViewSet.metadata-> GET  /api/v1/documents/{id}/metadonnees (fiche fichier).
 *
 * Aucune de ces routes ne remonte un document hors dossiers : les statistiques, l'autocomplétion et la
 * sélection sont bornées par le prédicat de lecture des droits.
 */
final class ApiDeuxVuesTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
        SchemaRecherche::installer($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->tearDownJonctions();
    }

    public function testLesStatistiquesAutocompletionSelectionEtAsnRespectentLesDroits(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $donnees = $this->compteEtDossiers();
        $mot = 'Unique' . $this->sel;
        $visible = $this->deposer($donnees['ecriture'], $mot . ' facture', 'a_controler', 'Alpha SA');
        $visible2 = $this->deposer($donnees['ecriture'], $mot . ' contrat', 'valide', 'Beta SA');
        $cache = $this->deposer($donnees['refus'], $mot . ' confidentiel', 'a_controler', 'Gamma SA');
        $jeton = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);

        // Statistiques : totaux cohérents, répartition par type MIME, prochain numéro d'archive.
        [$statut, $stats] = $this->appel($app, 'GET', '/api/v1/statistiques', $jeton);
        self::assertSame(200, $statut);
        self::assertGreaterThanOrEqual(2, $stats['documents_total']);
        self::assertGreaterThanOrEqual(1, $stats['documents_inbox']);
        self::assertSame((int) $stats['documents_total'],
            array_sum(array_map(static fn (array $l): int => (int) $l['total'], $stats['document_file_type_counts'])));
        self::assertGreaterThanOrEqual(1, $stats['document_type_count']);
        self::assertGreaterThanOrEqual((int) $stats['documents_total'], $stats['current_asn']);

        [$statutAsn, $asn] = $this->appel($app, 'GET', '/api/v1/next_asn', $jeton);
        self::assertSame(200, $statutAsn);
        self::assertIsInt($asn);
        self::assertSame((int) $stats['current_asn'], $asn);
        self::assertGreaterThan($visible2, $asn, 'le prochain numéro est au-delà des documents lisibles');

        // Autocomplétion : les titres lisibles, jamais le document refusé.
        [$statutAuto, $suggestions] = $this->appel($app, 'GET', '/api/v1/autocomplete?terme=' . rawurlencode($mot), $jeton);
        self::assertSame(200, $statutAuto);
        self::assertContains($mot . ' facture', $suggestions);
        self::assertContains($mot . ' contrat', $suggestions);
        self::assertNotContains($mot . ' confidentiel', $suggestions, 'l’autocomplétion ne révèle pas un document hors dossiers');

        // Sélection : compte des référentiels des documents LISIBLES ; un document refusé est refusé.
        [$statutSel, $selection] = $this->appel($app, 'POST', '/api/v1/documents/selection_data', $jeton,
            ['documents' => [$visible, $visible2]]);
        self::assertSame(200, $statutSel);
        self::assertSame(['Alpha SA' => 1, 'Beta SA' => 1], $this->plats($selection['emetteurs']));
        self::assertSame(['a_controler' => 1, 'valide' => 1], $this->plats($selection['statuts']));

        [$statutRefus, $refus] = $this->appel($app, 'POST', '/api/v1/documents/selection_data', $jeton,
            ['documents' => [$visible, $cache]]);
        self::assertSame(403, $statutRefus);
        self::assertStringContainsString('sélection refusée', (string) $refus['erreur']['cause']);

        // Fiche « métadonnées » : le visible l'est, le caché est refusé.
        [$statutMeta, $meta] = $this->appel($app, 'GET', '/api/v1/documents/' . $visible . '/metadonnees', $jeton);
        self::assertSame(200, $statutMeta);
        self::assertSame($visible, $meta['id']);
        self::assertSame('application/pdf', $meta['mime']);
        self::assertArrayHasKey('empreinte', $meta);
        self::assertArrayHasKey('versions', $meta);

        [$statutCache] = $this->appel($app, 'GET', '/api/v1/documents/' . $cache . '/metadonnees', $jeton);
        self::assertSame(403, $statutCache, 'la fiche d’un document hors dossiers est refusée');

        // Sans jeton, chaque route refuse 401 et se nomme.
        foreach ([['GET', '/api/v1/statistiques'], ['GET', '/api/v1/next_asn'],
            ['GET', '/api/v1/autocomplete?terme=x'], ['POST', '/api/v1/documents/selection_data'],
            ['GET', '/api/v1/documents/' . $visible . '/metadonnees']] as [$methode, $chemin]) {
            [$code, $corps] = $this->appel($app, $methode, $chemin, null, $methode === 'POST' ? ['documents' => [$visible]] : null);
            self::assertSame(401, $code, "$methode $chemin doit refuser sans jeton");
            self::assertArrayHasKey('erreur', $corps);
            self::assertArrayHasKey('etape', $corps['erreur']);
        }
    }

    /** @param list<array{nom:string,total:int}> $liste @return array<string,int> */
    private function plats(array $liste): array
    {
        $plat = [];
        foreach ($liste as $entree) {
            $plat[(string) $entree['nom']] = (int) $entree['total'];
        }

        return $plat;
    }

    private function deposer(string $dossier, string $titre, string $statut, string $emetteur): int
    {
        $id = (new DepotFichiers($this->config()))->deposer($this->piece($titre . '.pdf', $titre),
            ['titre' => $titre, 'dossier' => $dossier, 'emetteur' => $emetteur]);
        $this->pdo->prepare('UPDATE documents SET statut = ? WHERE id = ?')->execute([$statut, $id]);

        return $id;
    }
}
