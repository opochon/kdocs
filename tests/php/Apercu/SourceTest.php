<?php

declare(strict_types=1);

namespace Ged\Tests\Apercu;

use Ged\Apercu\Piece;
use Ged\Apercu\Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le dossier documentaire fait foi : une pièce se retrouve par son `id` lu dans son `.meta.json`,
 * jamais par un index parallèle. Aucune donnée réelle : corpus synthétique de `tests/corpus/apercu/`.
 */
final class SourceTest extends TestCase
{
    private static string $corpus;

    public static function setUpBeforeClass(): void
    {
        self::$corpus = dirname(__DIR__, 2) . '/corpus/apercu/documents';
        if (!is_file(self::$corpus . '/204-contrat-maintenance.pdf.meta.json')) {
            self::fail('Corpus absent ou non généré : lancez `php tests/corpus/apercu/generer.php`.');
        }
    }

    private function source(): Source
    {
        return new Source(self::$corpus);
    }

    public function testParIdResoutLaPieceDepuisLeDossier(): void
    {
        $piece = $this->source()->parId(204);

        self::assertInstanceOf(Piece::class, $piece);
        self::assertSame(204, $piece->id);
        self::assertSame('pdf', $piece->categorie);
        self::assertSame(3, $piece->pages);
        self::assertSame('Contrat de maintenance CM-2026-04', $piece->titre);
        self::assertTrue($piece->existe());
        self::assertSame(realpath(self::$corpus . '/204-contrat-maintenance.pdf'), $piece->chemin);
    }

    public function testParIdInconnuRetourneNull(): void
    {
        self::assertNull($this->source()->parId(999999));
    }

    public function testToutesListeLeCorpusTrieParId(): void
    {
        $pieces = $this->source()->toutes();

        self::assertGreaterThanOrEqual(6, count($pieces));
        $ids = array_map(static fn (Piece $p): int => $p->id, $pieces);
        $trie = $ids;
        sort($trie);
        self::assertSame($trie, $ids, 'les pièces sont rendues dans un ordre stable (id croissant)');
    }

    /** @return array<string, array{string, string}> */
    public static function extensions(): array
    {
        return [
            'pdf' => ['pdf', 'pdf'],
            'png' => ['png', 'image'],
            'jpg' => ['jpg', 'image'],
            'tiff' => ['tiff', 'image'],
            'docx' => ['docx', 'bureautique'],
            'xlsx' => ['xlsx', 'bureautique'],
            'odt' => ['odt', 'bureautique'],
            'inconnu' => ['bin', 'inconnu'],
        ];
    }

    #[DataProvider('extensions')]
    public function testCategorieDeduiteDeLExtension(string $extension, string $categorie): void
    {
        self::assertSame($categorie, Source::categorie($extension));
    }

    public function testUnMetaQuiSortDeLaRacineEstIgnore(): void
    {
        $bac = sys_get_temp_dir() . '/gedv2-apercu-' . bin2hex(random_bytes(4));
        mkdir($bac, 0777, true);
        file_put_contents($bac . '/fuite.pdf.meta.json', (string) json_encode([
            'id' => 1, 'titre' => 'Hors racine', 'categorie' => 'pdf', 'pages' => 1, 'fichier' => '../../secret.pdf',
        ]));

        try {
            self::assertNull((new Source($bac))->parId(1), 'une pièce hors de la racine n’est jamais servie');
        } finally {
            array_map('unlink', glob($bac . '/*') ?: []);
            rmdir($bac);
        }
    }
}
