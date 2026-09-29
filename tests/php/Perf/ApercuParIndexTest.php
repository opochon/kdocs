<?php

declare(strict_types=1);

namespace Ged\Tests\Perf;

use Ged\Apercu\Piece;
use Ged\Apercu\Source;
use PHPUnit\Framework\TestCase;

/**
 * Oracle PERF-1 (Aperçu) : `Source::parId()` résout le document par l'index (`documents.id`), sans
 * parcourir les 2 000 `.meta.json` du fonds. Preuve par le temps : la résolution indexée doit rester
 * bien en deçà d'un `toutes()`.
 */
final class ApercuParIndexTest extends TestCase
{
    use SupportFondsPerf;

    protected function setUp(): void
    {
        $this->setUpFondsPerf();
    }

    protected function tearDown(): void
    {
        $this->tearDownFondsPerf();
    }

    public function testApercuResoutParLIndexSansParcourirLeFonds(): void
    {
        $source = new Source($this->fonds(), $this->pdo());
        $id = $this->idValide();

        $piece = $source->parId($id);
        self::assertInstanceOf(Piece::class, $piece);
        self::assertSame($id, $piece->id);
        self::assertTrue($piece->existe());

        $dureeIndex = $this->dureeMinimale(fn (): ?Piece => $source->parId($id));
        $dureeParcours = $this->dureeMinimale(fn (): array => $source->toutes());

        self::assertLessThan($dureeParcours / 3, $dureeIndex,
            'la résolution par id passe par l’index, pas par un parcours des 2 000 .meta.json');
        self::assertLessThan(100.0, $dureeIndex);
    }
}
