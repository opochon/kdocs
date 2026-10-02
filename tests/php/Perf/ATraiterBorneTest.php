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

    public function testLesOrphelinsPlusNombreuxQueLaLimiteNeMasquentPasLaFileVivante(): void
    {
        $orphelins = $this->pdo()->query('SELECT id, chemin_relatif FROM documents WHERE id > ' . self::PERF_A_CONTROLER
            . ' ORDER BY id ASC LIMIT 1001')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1001, $orphelins, 'le fonds de charge fournit plus d’orphelins que la limite de file');

        foreach ($orphelins as $orphelin) {
            @unlink($this->fonds() . '/' . $orphelin['chemin_relatif']);
        }
        $ids = implode(',', array_map(static fn (array $ligne): int => (int) $ligne['id'], $orphelins));
        $this->pdo()->exec("UPDATE documents SET statut = 'a_controler', date_document = '2000-01-01', absent_depuis = NULL"
            . " WHERE id IN ($ids)");

        $donnees = (new ATraiter($this->config(), $this->pdo()))->donnees([], 'jeton');

        self::assertCount(self::PERF_A_CONTROLER, $donnees['file'],
            'les orphelins sans drapeau ne consomment pas la limite avant les pièces présentes');
        self::assertSame(self::PERF_A_CONTROLER, $donnees['bilan']['a_controler']);
    }
}
