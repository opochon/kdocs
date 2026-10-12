<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Socle\Champs;
use Ged\Socle\Collaboration;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Nomenclature;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Rejeu de la famille « filtres-ressources » du lot ged-parite-api-2.
 *
 * Vecteurs extraits en LECTURE SEULE de src/documents/tests/test_api_custom_fields.py (Paperless-ngx
 * v2.20.5) : la liste des champs personnalisés est filtrée par identifiant et par nom. Côté GEDv2, la
 * route `GET /api/v1/champs` rend les définitions de `Socle\Champs` (id, cle, nom, nature), au même
 * périmètre de droits (jeton lié à un compte).
 *
 * Les filtres des liens de partage (ShareLinkFilterSet.created / .expiration) sont prouvés directement
 * contre `GET /api/v1/partages` : un partage d'un document hors dossiers ne remonte jamais.
 */
final class ApiDeuxFiltresRessourcesTest extends TestCase
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

    public function testRejoueLesVecteursDesFiltresDeChampsPersonnalises(): void
    {
        $donnees = Vecteurs::charger('api-2-filtres-ressources');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $donneesCompte = $this->compteEtDossiers();
        $jeton = $this->jetonDocuments($donneesCompte['compte'], $donneesCompte['identifiant']);
        $ids = $this->creerLesChamps($donnees);

        $passes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $requete = is_array($entree['requete'] ?? null) ? $entree['requete'] : [];
            $sortie = is_array($vecteur['sortie'] ?? null) ? $vecteur['sortie'] : [];
            $identifiant = (string) ($vecteur['id'] ?? '?');
            $query = $this->traduire($requete, $ids);

            [$statut, $corps] = $this->appel($app, 'GET', '/api/v1/champs' . ($query === [] ? '' : '?' . http_build_query($query)), $jeton);
            $total = is_array($corps) ? count($corps) : -1;
            if ($statut === 200 && $total === (int) ($sortie['total'] ?? -1)) {
                ++$passes;
            } else {
                $echecs[] = sprintf('%s (%s) : attendu %s, obtenu statut %d / total %d', $identifiant,
                    (string) ($vecteur['reference'] ?? ''), json_encode($sortie['total'] ?? '?'), $statut, $total);
            }
        }

        Vecteurs::ecrireApi('2-filtres-ressources', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '2-filtres-ressources',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);

        self::assertSame([], $echecs, "vecteurs de filtres de ressources en écart :\n" . implode("\n", $echecs));
    }

    public function testLesFiltresDesLiensDePartageRespectentLesDroits(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $donnees = $this->compteEtDossiers();
        $visible = $this->deposer($donnees['ecriture'], 'Partageable');
        $cache = $this->deposer($donnees['refus'], 'Confidentiel');
        $collaboration = new Collaboration($this->config());
        $collaboration->partagerExterne($visible, 'test', '2030-01-01');
        $collaboration->partagerExterne($cache, 'test', '2031-01-01');
        $jeton = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);

        [$statut, $tous] = $this->appel($app, 'GET', '/api/v1/partages', $jeton);
        self::assertSame(200, $statut);
        $documents = array_map(static fn (array $p): int => (int) $p['document'], $tous);
        self::assertContains($visible, $documents);
        self::assertNotContains($cache, $documents, 'un partage ne révèle jamais un document hors dossiers');

        // Filtre d'expiration (ShareLinkFilterSet.expiration) : seule l'échéance du visible tombe avant 2031.
        [$s1, $avant] = $this->appel($app, 'GET', '/api/v1/partages?expire_avant=2030-06-01%2000:00:00', $jeton);
        self::assertSame(200, $s1);
        self::assertSame([$visible], array_map(static fn (array $p): int => (int) $p['document'], $avant));

        // Filtre de création (ShareLinkFilterSet.created) : les deux partages sont créés maintenant.
        [$s2, $recents] = $this->appel($app, 'GET', '/api/v1/partages?cree_apres=' . rawurlencode('2000-01-01 00:00:00'), $jeton);
        self::assertSame(200, $s2);
        self::assertContains($visible, array_map(static fn (array $p): int => (int) $p['document'], $recents));

        // Sans jeton, la porte refuse et se nomme (jamais un 500 muet).
        [$sans] = $this->appel($app, 'GET', '/api/v1/partages');
        self::assertSame(401, $sans);
        [$sansChamps] = $this->appel($app, 'GET', '/api/v1/champs');
        self::assertSame(401, $sansChamps);
    }

    /**
     * @param array<string, mixed> $donnees
     * @return array<string, int> nom du champ -> identifiant GEDv2
     */
    private function creerLesChamps(array $donnees): array
    {
        $nomenclature = new Nomenclature($this->config());
        $nomenclature->assurer();
        $typeId = (int) ($nomenclature->idDuType('Facture') ?? 1);
        $champs = new Champs($this->config());

        $existants = [];
        foreach ($champs->tous() as $definition) {
            $existants[$definition['nom']] = $definition['id'];
        }
        $natures = ['STRING' => 'texte', 'INT' => 'nombre', 'DATE' => 'date', 'FLOAT' => 'nombre',
            'BOOL' => 'booleen', 'MONETARY' => 'montant', 'URL' => 'url', 'SELECT' => 'liste'];
        foreach ($donnees['vecteurs'] as $vecteur) {
            foreach ((array) ($vecteur['entree']['champs'] ?? []) as $definition) {
                $nom = (string) $definition['nom'];
                if (isset($existants[$nom])) {
                    continue;
                }
                $nature = $natures[(string) $definition['nature']] ?? 'texte';
                $cree = $champs->ajouter($typeId, $nom, $nature, $nature === 'liste' ? ['Option 1'] : []);
                $existants[$nom] = $cree['id'];
            }
        }

        return $existants;
    }

    /**
     * @param array<string, mixed> $requete
     * @param array<string, int> $ids
     * @return array<string, string>
     */
    private function traduire(array $requete, array $ids): array
    {
        $query = [];
        foreach ($requete as $cle => $valeur) {
            switch ($cle) {
                case 'id':
                    $query['id'] = (string) ($ids[(string) $valeur] ?? 0);
                    break;
                case 'id__in':
                    $query['ids'] = implode(',', array_map(
                        static fn (string $nom): string => (string) ($ids[$nom] ?? 0), (array) $valeur));
                    break;
                case 'name__icontains':
                    $query['nom_contient'] = (string) $valeur;
                    break;
                default:
                    throw new RuntimeException('paramètre Paperless non câblé : ' . $cle);
            }
        }

        return $query;
    }

    private function deposer(string $dossier, string $titre): int
    {
        return (new DepotFichiers($this->config()))->deposer($this->piece($titre . '.pdf', $titre),
            ['titre' => $titre, 'dossier' => $dossier, 'emetteur' => 'Émetteur ' . $titre]);
    }
}
