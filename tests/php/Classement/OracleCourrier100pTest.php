<?php

declare(strict_types=1);

namespace Ged\Tests\Classement;

use Ged\Classement\Regles;
use PHPUnit\Framework\TestCase;

/**
 * Porte V2-03, rejouée par PHPUnit : le classement du lot courrier-100p est mesuré contre sa vérité.
 * Aucune IA : le socle déterministe propose des types justes mais ne VALIDE RIEN seul (règle
 * `typeCorrobore` : valider seul = habitude OU IA corroborante). Les pièces viennent du banc de
 * découpe (`php tools/banc/mesurer-decoupe.php courrier-100p`) ; sans elles, l'oracle est sauté.
 */
final class OracleCourrier100pTest extends TestCase
{
    use SupportClassement;

    protected function setUp(): void
    {
        $this->setUpClassement();
    }

    public function testZeroTypeFauxEtZeroEmetteurFauxSurCourrier100p(): void
    {
        $racine = dirname(__DIR__, 3);
        $oracle = json_decode((string) file_get_contents($racine . '/tests/oracles/classement/courrier-100p.json'), true, 512, JSON_THROW_ON_ERROR);
        $fPieces = $racine . '/' . $oracle['pieces'];
        if (!is_file($fPieces)) {
            self::markTestSkipped('Pièces absentes : lancez php tools/banc/mesurer-decoupe.php courrier-100p');
        }
        $pieces = json_decode((string) file_get_contents($fPieces), true, 512, JSON_THROW_ON_ERROR);
        $verite = json_decode((string) file_get_contents($racine . '/' . $oracle['verite']), true, 512, JSON_THROW_ON_ERROR);

        $parPage = [];
        foreach ($verite['elements'] as $el) {
            foreach ($el['pages'] as $page) {
                $parPage[$page] = $el;
            }
        }

        $classeur = $this->classeur();
        $mes = ['documents' => 0, 'valides' => 0, 'valides_type_faux' => 0, 'types_faux' => 0,
            'emetteurs_lus' => 0, 'emetteurs_faux' => 0, 'autre_valides_seuls' => 0];
        foreach ($pieces as $piece) {
            $el = $parPage[$piece['pages'][0]] ?? null;
            if ($el === null || !empty($el['blanche'])) {
                continue;
            }
            $mes['documents']++;
            $r = $classeur->proposer((string) $piece['texte'], (array) ($piece['analyse'] ?? []), basename((string) $piece['fichier']));
            $typeFaux = !Regles::memeType($r['type'], $el['type'] ?? null);
            $mes['types_faux'] += (int) $typeFaux;
            $mes['valides'] += (int) $r['valide'];
            $mes['valides_type_faux'] += (int) ($r['valide'] && $typeFaux);
            $mes['autre_valides_seuls'] += (int) ($r['valide'] && Regles::estAutre($r['type']));
            if (($r['emetteur'] ?? null) !== null && $r['emetteur'] !== '') {
                $mes['emetteurs_lus']++;
                $mes['emetteurs_faux'] += (int) (Regles::normaliser((string) $r['emetteur']) !== Regles::normaliser((string) ($el['correspondant'] ?? '')));
            }
        }

        self::assertSame((int) $oracle['attendu']['documents'], $mes['documents'], 'toutes les pièces du lot sont classées');
        self::assertSame(0, $mes['valides_type_faux'], 'aucun document validé seul avec un type faux');
        self::assertSame(0, $mes['types_faux'], 'aucun type proposé faux (validé ou en attente)');
        self::assertSame(0, $mes['emetteurs_faux'], 'aucun émetteur faux (non lu = vide)');
        self::assertSame(0, $mes['autre_valides_seuls'], '« Autre » jamais validé seul');
        self::assertSame($mes['documents'], $mes['emetteurs_lus'], 'tous les émetteurs sont lus sur ce lot');
        // Règle `typeCorrobore` : sans IA corroborante (et sans habitude enregistrée), un mot-clé
        // seul ne valide RIEN. Le document RH resterait de toute façon à contrôler.
        self::assertSame(
            (int) $oracle['attendu']['valides_seuls_sans_ia'],
            $mes['valides'],
            'sans IA, aucun document n’est rangé tout seul (hors habitudes)',
        );
    }
}
