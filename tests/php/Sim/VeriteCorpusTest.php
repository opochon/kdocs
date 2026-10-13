<?php

declare(strict_types=1);

namespace Ged\Tests\Sim;

use PHPUnit\Framework\TestCase;

/**
 * Oracle de l'espace de simulation (lot ged-sim-corpus-1) : la VÉRITÉ du corpus est un fichier versionné,
 * léger, écrit par le générateur — jamais déduit de l'application. Ce test ne dépend pas du corpus
 * régénéré (binaire, hors dépôt) : il vérifie le CONTRAT de la vérité (structure, complétude, cohérence
 * des requêtes) et la présence du générateur qui la produit.
 *
 * Rouge tant que le générateur et la vérité n'existent pas : c'est l'oracle d'abord.
 */
final class VeriteCorpusTest extends TestCase
{
    private const GENERATEUR = '/tools/sim/generer-corpus.py';
    private const VERITE = '/recette/sim/verite-corpus.json';

    /** Le corpus visé par la mission : volumineux, proche du réel, mais synthétique. */
    private const DOCUMENTS_MIN = 150;
    private const DOCUMENTS_MAX = 300;

    private static function racine(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, mixed> */
    private static function verite(): array
    {
        $fichier = self::racine() . self::VERITE;
        self::assertFileExists($fichier, 'la vérité versionnée du corpus doit exister');
        $verite = json_decode((string) file_get_contents($fichier), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($verite);

        return $verite;
    }

    public function testLeGenerateurReproductibleExiste(): void
    {
        $generateur = self::racine() . self::GENERATEUR;
        self::assertFileExists($generateur, 'le générateur du corpus doit exister');
        $source = (string) file_get_contents($generateur);
        self::assertStringContainsString('SEED', $source, 'le générateur doit porter une graine fixe');
        self::assertStringContainsString('pymupdf', $source, 'le générateur produit des PDF via PyMuPDF');
    }

    public function testLaVeriteEstLegereEtVersionnee(): void
    {
        $verite = self::verite();
        self::assertSame(1, $verite['version'] ?? null, 'format de vérité attendu : version 1');
        self::assertIsInt($verite['seed'] ?? null, 'la graine du générateur doit être figée dans la vérité');
        self::assertArrayHasKey('documents', $verite);
        self::assertArrayHasKey('requetes', $verite);

        // « légère » : la vérité ne porte pas le texte intégral des documents.
        self::assertLessThan(600_000, (int) filesize(self::racine() . self::VERITE),
            'la vérité doit rester légère (pas de texte de document dedans)');
    }

    public function testLeCorpusALaVolumetrieAttendue(): void
    {
        $documents = self::verite()['documents'];
        self::assertIsArray($documents);
        self::assertGreaterThanOrEqual(self::DOCUMENTS_MIN, count($documents), 'au moins 150 documents');
        self::assertLessThanOrEqual(self::DOCUMENTS_MAX, count($documents), 'au plus 300 documents');

        $gros = array_filter($documents, static fn (array $d): bool => (int) ($d['pages'] ?? 0) >= 50);
        self::assertGreaterThanOrEqual(4, count($gros), 'le corpus doit contenir de GROS PDF multi-pages (>= 50 pages)');
    }

    public function testChaqueDocumentPorteSonClassementAttendu(): void
    {
        $ids = [];
        foreach (self::verite()['documents'] as $document) {
            self::assertIsArray($document);
            foreach (['id', 'fichier', 'type', 'emetteur', 'destinataire', 'date', 'langue', 'pages', 'scan'] as $cle) {
                self::assertArrayHasKey($cle, $document, "chaque document porte « $cle »");
            }
            self::assertNotSame('', trim((string) $document['id']));
            self::assertNotSame('', trim((string) $document['fichier']));
            self::assertNotSame('', trim((string) $document['type']), 'le type attendu est toujours nommé');
            self::assertNotSame('', trim((string) $document['emetteur']), 'l’émetteur attendu est toujours nommé');
            self::assertNotSame('', trim((string) $document['destinataire']));
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $document['date']);
            self::assertGreaterThanOrEqual(1, (int) $document['pages']);
            $ids[] = (string) $document['id'];
        }
        self::assertSame(count($ids), count(array_unique($ids)), 'les identifiants de document sont uniques');
    }

    public function testChaqueRequeteReferenceDesDocumentsExistants(): void
    {
        $verite = self::verite();
        $ids = array_flip(array_map(static fn (array $d): string => (string) $d['id'], $verite['documents']));
        $requetes = $verite['requetes'];
        self::assertIsArray($requetes);
        self::assertGreaterThanOrEqual(8, count($requetes), 'au moins huit requêtes de mesure');

        $idsRequetes = [];
        foreach ($requetes as $requete) {
            self::assertIsArray($requete);
            $id = (string) ($requete['id'] ?? '');
            self::assertNotSame('', $id, 'chaque requête est nommée');
            $idsRequetes[] = $id;
            self::assertArrayHasKey('attendu', $requete);
            self::assertNotEmpty($requete['attendu'], "la requête « $id » annonce ses documents attendus");
            // Une requête porte un texte ou au moins un filtre : jamais un vide muet.
            self::assertTrue(trim((string) ($requete['q'] ?? '')) !== '' || !empty($requete['filtres']),
                "la requête « $id » porte un texte ou un filtre");
            foreach ($requete['attendu'] as $ref) {
                self::assertArrayHasKey((string) $ref, $ids, "la requête « $id » référence un document connu ($ref)");
            }
        }
        self::assertSame(count($idsRequetes), count(array_unique($idsRequetes)), 'les identifiants de requête sont uniques');
    }

    public function testLeCorpusEstAnnonceSynthetiqueEtAnonyme(): void
    {
        $verite = self::verite();
        self::assertTrue((bool) ($verite['_synthetique'] ?? false),
            'la vérité doit déclarer un corpus synthétique (aucune donnée réelle)');
        self::assertNotSame('', trim((string) ($verite['_genere_par'] ?? '')), 'la provenance de la vérité est écrite');
    }
}
