<?php

declare(strict_types=1);

namespace Ged\Tests\Perf;

use Ged\Socle\EcranBibliotheque;
use PHPUnit\Framework\TestCase;

/**
 * Oracle PERF (Socle, V2-04) : la bibliothèque LISTE par l'index, pas en marchant le dossier —
 * comme « À traiter », l'aperçu et la recherche avant elle. Preuves jumelles :
 * - le compteur d'accès du serveur : UN passage de l'index, pas une requête par document ;
 * - le fonds ÉLOIGNÉ : la liste vit dans l'index (drapeaux de vie), le panneau du document retenu
 *   tombe en repli sur la copie indexée — l'écran répond, sans jamais marcher le dossier.
 */
final class BibliothequeParIndexTest extends TestCase
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

    public function testLaBibliothequeListeParLIndexSansParcourirLeFonds(): void
    {
        $ecran = new EcranBibliotheque($this->config());

        $avant = $this->compteurAcces('Handler_read_rnd_next');
        $donnees = $ecran->donnees([], 'jeton');
        $apres = $this->compteurAcces('Handler_read_rnd_next');

        // PERF-3 : la liste est PAGINÉE côté base — une page bornée, jamais tout le fonds rendu.
        self::assertLessThanOrEqual(EcranBibliotheque::PAGE_TAILLE, count($donnees['docs']),
            'la bibliothèque rend une page, pas le fonds entier');
        self::assertSame(self::PERF_DOCUMENTS, $donnees['total']);
        // Un écran-LISTE ne lit que sa page (ORDER BY id DESC LIMIT) : le compteur de lignes lues reste
        // très en deçà du fonds, preuve qu'aucune lecture de `meta` sur tout l'index n'a lieu.
        self::assertLessThan(self::PERF_DOCUMENTS / 4, $apres - $avant,
            'la liste doit coûter une page, pas un balayage des 2 000 documents');
        self::assertLessThan(500.0, $this->dureeMinimale(fn (): array => $ecran->donnees([], 'jeton')),
            '2 000 documents listés en moins de 500 ms : relire tout l’index ne le pourrait pas');
    }

    public function testLaBibliothequeRepondFondsEloigne(): void
    {
        $ecran = new EcranBibliotheque($this->config());
        $this->eloignerFonds(true);

        try {
            $donnees = $ecran->donnees(['doc' => (string) $this->idValide()], 'jeton');
            self::assertLessThanOrEqual(EcranBibliotheque::PAGE_TAILLE, count($donnees['docs']),
                'la liste vit dans l’index : le dossier éloigné ne la vide ni ne la casse');
            self::assertSame($this->idValide(), $donnees['apercu']['id'],
                'le document retenu reste désignable : son panneau tombe sur la copie indexée');
        } finally {
            $this->eloignerFonds(false);
        }
    }
}
