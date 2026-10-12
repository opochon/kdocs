<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Base;
use Ged\Config;
use Ged\Jonctions\Catalogue;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\Schema as SchemaSocle;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Rejeu de la famille « filtres-documents » du lot ged-parite-api-2.
 *
 * Vecteurs extraits en LECTURE SEULE de src/documents/tests/test_api_documents.py (Paperless-ngx v2.20.5) :
 * corpus synthétique, requête de liste, ensemble de documents retenus. Le rejeu interroge la VRAIE couche
 * de requêtes de GEDv2 (`Jonctions\Catalogue::lister`) sur la base `gedv2_test`, jamais une maquette.
 *
 * Nouveauté api-2 : les filtres de champs personnalisés (`custom_fields__icontains` -> `champs_contient`)
 * sont câblés, et les filtres d'existence/appartenance (`has_custom_fields`, `custom_fields__id__all/in/none`)
 * sont prouvés sur la même vraie couche. La correspondance Paperless -> GEDv2 est explicite dans `traduire()`.
 */
final class ApiDeuxFiltresDocumentsTest extends TestCase
{
    private PDO $pdo;
    private string $sel;

    protected function setUp(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);
        SchemaSocle::installer($this->pdo);
        SchemaSecurite::installer($this->pdo);
        SchemaRecherche::installer($this->pdo);
        $this->sel = bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        $this->nettoyer('%' . $this->sel . '%');
    }

    public function testRejoueLesVecteursDesFiltresDeDocuments(): void
    {
        $donnees = Vecteurs::charger('api-2-filtres-documents');
        $config = new Config(dirname(__DIR__, 3));

        $passes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $corpus = is_array($entree['corpus'] ?? null) ? $entree['corpus'] : [];
            $requete = is_array($entree['requete'] ?? null) ? $entree['requete'] : [];
            $sortie = is_array($vecteur['sortie'] ?? null) ? $vecteur['sortie'] : [];
            $identifiant = (string) ($vecteur['id'] ?? '?');

            $prefixe = 'parite-api2/' . $this->sel . '/' . md5($identifiant);
            try {
                $idsParCle = $this->insererCorpus($corpus, $prefixe);
                $criteres = $this->traduire($requete, $idsParCle);
                if (!isset($criteres['id']) && !isset($criteres['ids'])) {
                    $criteres['ids'] = implode(',', array_values($idsParCle));
                }
                $page = (new Catalogue($config, 0, true))->lister($criteres, 'rejeu ' . $identifiant);
                $obtenu = array_map(static fn (array $d): string => (string) $d['nom'], $page['documents']);
                sort($obtenu);

                $attendu = null;
                if (isset($sortie['retenus']) && is_array($sortie['retenus'])) {
                    $attendu = array_map('strval', $sortie['retenus']);
                    sort($attendu);
                }
                $ok = $attendu !== null
                    ? $obtenu === $attendu
                    : count($obtenu) === (int) ($sortie['total'] ?? -1);
                if ($ok) {
                    ++$passes;
                } else {
                    $echecs[] = sprintf('%s (%s) : attendu %s, obtenu %s', $identifiant,
                        (string) ($vecteur['reference'] ?? ''), json_encode($attendu ?? ($sortie['total'] ?? '?')),
                        json_encode($obtenu));
                }
            } finally {
                $this->nettoyer($prefixe . '%');
            }
        }

        Vecteurs::ecrireApi('2-filtres-documents', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '2-filtres-documents',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);

        self::assertSame([], $echecs, "vecteurs de filtres de documents en écart :\n" . implode("\n", $echecs));
    }

    /**
     * Filtres des champs personnalisés qui ne viennent pas des vecteurs : existence, appartenance
     * (toutes/aucune). Mêmes couples que DocumentFilterSet (Paperless 2.20.5, src/documents/filters.py),
     * prouvés sur la même vraie couche de requêtes ; la clé est celle de la définition GEDv2.
     */
    public function testLesFiltresDesChampsPersonnalisesSontCables(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        $prefixe = 'parite-api2/' . $this->sel . '/champs';
        $corpus = [
            ['cle' => 'a', 'titre' => 'Alpha', 'champs' => ['stringfield' => 'foobard1', 'numberfield' => 999]],
            ['cle' => 'b', 'titre' => 'Beta', 'champs' => ['stringfield' => 'foobard4']],
            ['cle' => 'c', 'titre' => 'Gamma', 'champs' => []],
        ];

        try {
            $ids = $this->insererCorpus($corpus, $prefixe);
            $perimetre = implode(',', array_values($ids));
            $catalogue = new Catalogue($config, 0, true);
            $noms = fn (array $criteres): array => $this->noms($catalogue, $criteres, $perimetre);

            self::assertSame(['a', 'b'], $noms(['champs_presents' => '1']));
            self::assertSame(['c'], $noms(['champs_presents' => '0']));
            self::assertSame(['a'], $noms(['champs_tous' => 'stringfield,numberfield']));
            self::assertSame(['a', 'b'], $noms(['champs_any' => 'stringfield']));
            self::assertSame(['c'], $noms(['sans_champs' => 'stringfield']));
            self::assertSame(['a'], $noms(['champs_contient' => 'foobard1']), 'valeur de champ, casse ignorée');
            self::assertSame(['a', 'b'], $noms(['champs_contient' => 'stringfield']), 'nom de champ');
            self::assertSame([], $noms(['champs_contient' => 'inexistant']));

            try {
                $catalogue->lister(['champs_presents' => 'peut-être']);
                self::fail('un booléen invalide doit être refusé');
            } catch (\Ged\Jonctions\ErreurApi $e) {
                self::assertSame(400, $e->statut);
                self::assertStringContainsString('champs_presents', $e->cause);
            }
        } finally {
            $this->nettoyer('%' . $this->sel . '%');
        }
    }

    /**
     * Traduction fonctionnelle Paperless -> GEDv2 des paramètres de liste.
     *
     * @param array<string, mixed> $requete
     * @param array<string, int> $idsParCle
     * @return array<string, mixed>
     */
    private function traduire(array $requete, array $idsParCle): array
    {
        $criteres = [];
        foreach ($requete as $cle => $valeur) {
            $valeur = is_array($valeur) ? array_map('strval', $valeur) : (string) $valeur;
            switch ($cle) {
                case 'is_in_inbox':
                    $criteres['a_traiter'] = $valeur === 'true' ? '1' : '0';
                    break;
                case 'tags__id__in':
                    $criteres['etiquettes_any'] = implode(',', (array) $valeur);
                    break;
                case 'tags__id__all':
                    $criteres['etiquette'] = (array) $valeur;
                    break;
                case 'tags__id__none':
                    $criteres['sans_etiquettes'] = implode(',', (array) $valeur);
                    break;
                case 'id':
                    $criteres['id'] = (string) $idsParCle[(string) $valeur];
                    break;
                case 'id__in':
                    $ids = [];
                    foreach ((array) $valeur as $cleDocument) {
                        $ids[] = (string) $idsParCle[(string) $cleDocument];
                    }
                    $criteres['ids'] = implode(',', $ids);
                    break;
                case 'mime_type':
                    $criteres['mime_contient'] = $valeur;
                    break;
                case 'custom_fields__icontains':
                    $criteres['champs_contient'] = $valeur;
                    break;
                default:
                    throw new RuntimeException('paramètre Paperless non câblé : ' . $cle);
            }
        }

        return $criteres;
    }

    /**
     * @param list<array<string, mixed>> $corpus
     * @return array<string, int>
     */
    private function insererCorpus(array $corpus, string $prefixe): array
    {
        $ids = [];
        foreach ($corpus as $document) {
            $cle = (string) $document['cle'];
            $chemin = is_string($document['chemin'] ?? null) ? (string) $document['chemin'] : $prefixe . '/' . $cle . '.pdf';
            $etiquettes = (array) ($document['etiquettes'] ?? []);
            $meta = ['titre' => (string) $document['titre']];
            $champs = (array) ($document['champs'] ?? []);
            if ($champs !== []) {
                $meta['champs'] = $champs;
            }
            $this->pdo->prepare('INSERT INTO documents (chemin_relatif, nom, titre, type, emetteur, date_document, '
                . 'etiquettes, source, statut, empreinte, taille, mime, meta, cree_le, maj_le) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $chemin, $cle, (string) $document['titre'], $document['type'] ?? null,
                    $document['emetteur'] ?? null, $document['date'] ?? null,
                    $etiquettes === [] ? null : json_encode($etiquettes, JSON_UNESCAPED_UNICODE),
                    'ecran', (string) ($document['statut'] ?? 'valide'),
                    (string) ($document['empreinte'] ?? hash('sha256', $cle)), 1,
                    (string) ($document['mime'] ?? 'application/pdf'),
                    (string) json_encode($meta, JSON_UNESCAPED_UNICODE),
                    (string) ($document['ajoute'] ?? '2024-01-01 00:00:00'),
                    (string) ($document['ajoute'] ?? '2024-01-01 00:00:00'),
                ]);
            $ids[$cle] = (int) $this->pdo->lastInsertId();

            $contenu = (string) ($document['contenu'] ?? '');
            if ($contenu !== '') {
                $this->pdo->prepare('INSERT INTO recherche_textes (cle, chemin, document_id, titre, contenu, signature) '
                    . 'VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([hash('sha256', $chemin), $chemin, $ids[$cle], (string) $document['titre'],
                        $contenu, hash('sha256', 'sig-' . $cle)]);
            }
        }

        return $ids;
    }

    /** @param array<string, mixed> $criteres @return list<string> */
    private function noms(Catalogue $catalogue, array $criteres, string $perimetre): array
    {
        if (!isset($criteres['id']) && !isset($criteres['ids'])) {
            $criteres['ids'] = $perimetre;
        }
        $page = $catalogue->lister($criteres, 'test champs');
        $noms = array_map(static fn (array $d): string => (string) $d['nom'], $page['documents']);
        sort($noms);

        return $noms;
    }

    private function nettoyer(string $motif): void
    {
        $this->pdo->prepare('DELETE FROM recherche_textes WHERE chemin LIKE ?')->execute([$motif]);
        $this->pdo->prepare('DELETE FROM documents WHERE chemin_relatif LIKE ?')->execute([$motif]);
    }
}
