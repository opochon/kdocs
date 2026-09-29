<?php

declare(strict_types=1);

namespace Ged\Tests\Perf;

use Ged\Ui\ATraiter;
use PHPUnit\Framework\TestCase;

/**
 * Oracle PERF-1 (Interface) : l'écran « À traiter » ne doit plus lire le fonds entier — il interroge
 * l'index par statut `a_controler`. Preuve par le compteur d'accès du serveur : une requête indexée lit
 * quelques lignes, un parcours de `documents` en lit 2 000.
 */
final class ATraiterBorneTest extends TestCase
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

    public function testATraiterNeLitPasLeFondsEntier(): void
    {
        $atraiter = new ATraiter($this->config(), $this->pdo());
        $avant = $this->compteurAcces('Handler_read_rnd_next');
        $donnees = $atraiter->donnees([], 'jeton');
        $apres = $this->compteurAcces('Handler_read_rnd_next');

        self::assertCount(self::PERF_A_CONTROLER, $donnees['file'], 'la file ne contient que les documents a_controler');
        self::assertSame(self::PERF_A_CONTROLER, $donnees['bilan']['a_controler']);
        self::assertSame(self::PERF_DOCUMENTS - self::PERF_A_CONTROLER, $donnees['bilan']['auto']);
        self::assertLessThan(500, $apres - $avant,
            'l’écran doit lire la file indexée (statut), pas parcourir les 2 000 documents du fonds');
    }
}
