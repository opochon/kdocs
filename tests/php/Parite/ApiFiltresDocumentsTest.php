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
 * Rejeu de la famille « filtres-documents » du lot ged-parite-api-1.
 *
 * Vecteurs extraits en LECTURE SEULE de src/documents/tests/test_api_documents.py (Paperless-ngx v2.20.5) :
 * corpus synthétique, requête de liste, ensemble de documents retenus. Le rejeu interroge la VRAIE couche
 * de requêtes de GEDv2 (`Jonctions\Catalogue::lister`) sur la base `gedv2_test`, jamais une maquette.
 *
 * La correspondance fonctionnelle Paperless -> GEDv2 est explicite dans `traduire()` : les noms de
 * paramètres diffèrent (GEDv2 a ses propres conventions, en français), le comportement est le même.
 * Les paramètres Paperless qui n'ont pas d'équivalent dans le modèle GEDv2 (champs personnalisés, nom
 * d'origine, identifiants d'étiquettes) ne sont pas câblés : ils sont listés `non_rejouables` par
 * l'extracteur, avec leur raison et leur référence.
 */
final class ApiFiltresDocumentsTest extends TestCase
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
        $donnees = Vecteurs::charger('api-filtres-documents');
        $config = new Config(dirname(__DIR__, 3));

        $passes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $corpus = is_array($entree['corpus'] ?? null) ? $entree['corpus'] : [];
            $requete = is_array($entree['requete'] ?? null) ? $entree['requete'] : [];
            $sortie = is_array($vecteur['sortie'] ?? null) ? $vecteur['sortie'] : [];
            $identifiant = (string) ($vecteur['id'] ?? '?');

            $prefixe = 'parite-api/' . $this->sel . '/' . md5($identifiant);
            try {
                $idsParCle = $this->insererCorpus($corpus, $prefixe);
                $criteres = $this->traduire($requete, $idsParCle);
                // Isole le corpus de ce vecteur des documents laissés par d'autres tests sur gedv2_test :
                // le périmètre est déjà sans ACL (administrateur), on le borne aux lignes insérées.
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

        Vecteurs::ecrireApi('filtres-documents', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => 'filtres-documents',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);

        self::assertSame([], $echecs, "vecteurs de filtres de documents en écart :\n" . implode("\n", $echecs));
    }

    /**
     * Bornes, opérateurs de texte et filtres d'existence : chaque comportement de DocumentFilterSet qui
     * n'est pas dans les vecteurs extraits (dates, texte, étiquettes, emplacement) est prouvé ici, sur la
     * même vraie couche de requêtes.
     */
    public function testLesFiltresDeBornesDExistenceEtDeTexteSontCables(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        $prefixe = 'parite-api/' . $this->sel . '/bornes';
        $corpus = [
            ['cle' => 'a', 'titre' => 'Facture Alpha', 'mime' => 'application/pdf', 'empreinte' => 'aaa1',
                'etiquettes' => ['Urgent'], 'statut' => 'a_controler', 'date' => '2024-01-10',
                'ajoute' => '2024-02-01 08:00:00', 'modifie' => '2024-03-01 09:00:00', 'type' => 'Facture',
                'emetteur' => 'Alpha SA', 'chemin' => $prefixe . '/a.pdf'],
            ['cle' => 'b', 'titre' => 'Recu Beta', 'mime' => 'image/png', 'empreinte' => 'bbb2',
                'etiquettes' => [], 'statut' => 'valide', 'date' => '2024-06-15',
                'ajoute' => '2024-07-01 08:00:00', 'modifie' => '2024-08-01 09:00:00', 'type' => null,
                'emetteur' => null, 'chemin' => 'sans-dossier-' . $this->sel . '.pdf'],
            ['cle' => 'c', 'titre' => 'Contrat Gamma', 'mime' => 'application/pdf', 'empreinte' => 'ccc3',
                'etiquettes' => ['Urgent', 'Contrat'], 'statut' => 'valide', 'date' => '2025-01-05',
                'ajoute' => '2025-02-01 08:00:00', 'modifie' => '2025-03-01 09:00:00', 'type' => 'Contrat',
                'emetteur' => 'Gamma SARL', 'chemin' => $prefixe . '/c.pdf'],
        ];

        try {
            $ids = $this->insererCorpus($corpus, $prefixe);
            $perimetre = implode(',', array_values($ids));
            $catalogue = new Catalogue($config, 0, true);
            $noms = fn (array $criteres): array => $this->noms($catalogue, $criteres, $perimetre);

            self::assertSame(['a', 'b', 'c'], $noms([]), 'sans filtre : tout le corpus');
            self::assertSame(['b'], $noms(['id' => (string) $ids['b']]));
            self::assertSame(['a', 'c'], $noms(['ids' => $ids['a'] . ',' . $ids['c']]));

            self::assertSame(['a'], $noms(['titre_egal' => 'facture alpha']), 'titre égal, casse ignorée');
            self::assertSame(['a', 'b', 'c'], $noms(['titre_contient' => 'a']));
            self::assertSame(['c'], $noms(['titre_commence' => 'Contrat']));
            self::assertSame(['b'], $noms(['titre_finit' => 'Beta']));

            self::assertSame(['a', 'c'], $noms(['mime_contient' => 'pdf']));
            self::assertSame(['b'], $noms(['empreinte_egal' => 'BBB2']), 'empreinte casse ignorée');

            self::assertSame(['a'], $noms(['etiquettes_presentes' => '1', 'titre_contient' => 'Alpha']));
            self::assertSame(['b'], $noms(['sans_etiquettes' => 'Urgent,Contrat']));
            self::assertSame(['a', 'c'], $noms(['etiquettes_any' => 'Contrat,Urgent']));
            self::assertSame(['a'], $noms(['a_traiter' => '1']));
            self::assertSame(['b', 'c'], $noms(['a_traiter' => '0']));

            self::assertSame(['c'], $noms(['date_min' => '2024-06-16']));
            self::assertSame(['a', 'b'], $noms(['date_max' => '2024-06-15']));
            self::assertSame(['c'], $noms(['date_apres' => '2024-06-15']));
            self::assertSame(['a'], $noms(['date_avant' => '2024-06-15']));

            self::assertSame(['c'], $noms(['ajoute_min' => '2025-01-01']));
            self::assertSame(['a', 'b'], $noms(['modifie_max' => '2024-08-01 23:59:59']));

            self::assertSame(['b'], $noms(['sans_emetteur' => '1']));
            self::assertSame(['b'], $noms(['sans_type' => '1']));
            self::assertSame(['b'], $noms(['sans_dossier' => '1']));

            // Les filtres passent bien par la même porte : un filtre invalide est refusé, jamais lâché.
            try {
                $catalogue->lister(['a_traiter' => 'peut-être']);
                self::fail('un booléen invalide doit être refusé');
            } catch (\Ged\Jonctions\ErreurApi $e) {
                self::assertSame(400, $e->statut);
                self::assertStringContainsString('a_traiter', $e->cause);
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
                case 'is_in_inbox':   // tag « boîte de réception » Paperless = statut À traiter de GEDv2
                    $criteres['a_traiter'] = $valeur === 'true' ? '1' : '0';
                    break;
                case 'tags__id__in':  // identifiants de tags Paperless = noms d'étiquettes GEDv2
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
                case 'id__in':        // clés de corpus -> identifiants réels insérés
                    $ids = [];
                    foreach ((array) $valeur as $cleDocument) {
                        $ids[] = (string) $idsParCle[(string) $cleDocument];
                    }
                    $criteres['ids'] = implode(',', $ids);
                    break;
                case 'mime_type':
                    $criteres['mime_contient'] = $valeur;
                    break;
                case 'checksum__iexact':
                    $criteres['empreinte_egal'] = $valeur;
                    break;
                case 'title_content':
                    $criteres['titre_contenu'] = trim($valeur);
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
            $ajoute = (string) ($document['ajoute'] ?? '2024-01-01 00:00:00');
            $this->pdo->prepare('INSERT INTO documents (chemin_relatif, nom, titre, type, emetteur, date_document, '
                . 'etiquettes, source, statut, empreinte, taille, mime, meta, cree_le, maj_le) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $chemin, $cle, (string) $document['titre'], $document['type'] ?? null, $document['emetteur'] ?? null,
                    $document['date'] ?? null, $etiquettes === [] ? null : json_encode($etiquettes, JSON_UNESCAPED_UNICODE),
                    'ecran', (string) ($document['statut'] ?? 'valide'), (string) $document['empreinte'], 1,
                    (string) $document['mime'], (string) json_encode(['titre' => $document['titre']], JSON_UNESCAPED_UNICODE),
                    $ajoute, (string) ($document['modifie'] ?? $ajoute),
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
        // Le périmètre borne la lecture au corpus de ce test ; les filtres testés s'y ajoutent.
        if (!isset($criteres['id']) && !isset($criteres['ids'])) {
            $criteres['ids'] = $perimetre;
        }
        $page = $catalogue->lister($criteres, 'test bornes');
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
