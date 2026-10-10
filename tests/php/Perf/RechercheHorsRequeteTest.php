<?php

declare(strict_types=1);

namespace Ged\Tests\Perf;

use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Requete;
use Ged\Recherche\Service;
use PHPUnit\Framework\TestCase;

/**
 * Oracle PERF-1 (Recherche & aperçu) : la synchronisation de l'index plein texte vit HORS de la requête
 * web (job `tools/indexer-recherche.php` / `tools/tache.php indexation`). Preuve : une fois l'index bâti,
 * on éloigne le fonds ; si la recherche le relisait encore, elle verrait un fonds vide et ne trouverait
 * plus rien.
 */
final class RechercheHorsRequeteTest extends TestCase
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

    public function testRechercheRepondDepuisLIndexSansRelireLeFonds(): void
    {
        $index = IndexPleinTexte::depuis($this->config(), $this->pdo());
        self::assertGreaterThan(0, $index->synchroniser(null), 'l’index est bâti hors requête (job)');
        $moteur = new Service($this->pdo());

        $this->eloignerFonds(true);
        try {
            $resultat = $moteur->rechercher(new Requete('maintenance'));
        } finally {
            $this->eloignerFonds(false);
        }

        self::assertNull($resultat->erreur?->cause);
        self::assertSame('fulltext', $resultat->mode);
        self::assertNotEmpty($resultat->trouvailles, 'la recherche répond depuis l’index, sans relire le fonds');
        self::assertLessThan(1000.0, $resultat->dureeMs);
    }
}
