<?php

declare(strict_types=1);

namespace Ged\Tests\Recherche;

use Ged\Recherche\Requete;
use PHPUnit\Framework\TestCase;

/**
 * Porte F5 du lot V2-05b, rejouée par PHPUnit : le fonds de `tests/oracles/recherche/recherche.json` est
 * déposé par `Ged\Socle\DepotFichiers`, puis chaque requête doit rendre ses attendus EN TÊTE, dans l'ordre
 * annoncé, en moins d'une seconde (mesure à chaud). Aucune IA, aucune donnée réelle.
 */
final class OracleRechercheTest extends TestCase
{
    use SupportRecherche;

    private const ORACLE = '/tests/oracles/recherche/recherche.json';

    protected function setUp(): void
    {
        $this->setUpRecherche();
    }

    protected function tearDown(): void
    {
        $this->tearDownRecherche();
    }

    public function testTousLesAttendusEnTeteDansLOrdreEtSousUneSeconde(): void
    {
        $oracle = json_decode((string) file_get_contents(dirname(__DIR__, 3) . self::ORACLE), true, 512, JSON_THROW_ON_ERROR);
        /** @var list<array<string, mixed>> $corpus */
        $corpus = $oracle['corpus'];
        $titreParRef = [];
        foreach ($corpus as $document) {
            $titreParRef[(string) $document['ref']] = (string) $document['titre'];
        }
        $this->deposerCorpus($corpus);

        $moteur = $this->moteur();
        // Les appelants ne paient pas la première synchronisation dans la mesure de la porte.
        $moteur->rechercher(new Requete(''));

        $max = (int) $oracle['porte']['max_ms_par_requete'];
        foreach ($oracle['requetes'] as $requete) {
            $parametres = array_merge(['q' => (string) $requete['q']], (array) ($requete['filtres'] ?? []));
            $resultat = $moteur->rechercher(Requete::depuisParametres($parametres));
            $libelle = 'requête « ' . $requete['q'] . ' »';

            self::assertNull($resultat->erreur?->cause, $libelle . ' : ' . ($resultat->erreur?->cause ?? ''));
            if (isset($requete['mode'])) {
                self::assertSame((string) $requete['mode'], $resultat->mode, $libelle . ' : mode de recherche');
            }

            $attendus = array_map(
                static fn (array $a): string => $titreParRef[(string) $a['ref']],
                (array) $requete['attendu'],
            );
            $obtenus = array_map(static fn ($t): string => $t->titre, $resultat->trouvailles);
            self::assertSame($attendus, array_slice($obtenus, 0, count($attendus)), $libelle . ' : attendus en tête');
            self::assertLessThan($max, $resultat->dureeMs, $libelle . ' : moins d’une seconde');
        }
    }
}
