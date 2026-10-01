<?php

declare(strict_types=1);

namespace Ged\Tests;

use Ged\Ui\ExtraitsLisibles;
use PHPUnit\Framework\TestCase;

/**
 * V2-10c / V2-10d — les extraits « Documents semblables » ne doivent jamais montrer de texte OCR brut.
 *
 * Deux règles se combinent :
 * 1. un extrait dont une part notable des mots sont des fragments de ≤ 3 caractères hors petits mots
 *    français, allemands et italiens (et abréviations administratives courantes), ou qui porte des
 *    signes parasites, n'est pas « propre » ;
 * 2. quand un extrait mélange bruit et texte, il est rogné au premier segment propre ; s'il n'en porte
 *    aucun, rien n'est affiché (titre, type, émetteur et date suffisent).
 *
 * Le corpus `corpus/ocr-extraits.php` rejoue des formes réalistes de scans (en-têtes, adresses,
 * montants, bruit de tampons et de logos). Aucune donnée réelle.
 */
final class ExtraitsLisiblesTest extends TestCase
{
    /** @var list<array{nom:string, categorie:string, texte:string, attendu:?string, interdits:list<string>}>|null */
    private static ?array $corpus = null;

    /** @return list<array{nom:string, categorie:string, texte:string, attendu:?string, interdits:list<string>}> */
    private static function corpus(): array
    {
        if (self::$corpus === null) {
            /** @var list<array{nom:string, categorie:string, texte:string, attendu:?string, interdits:list<string>}> $corpus */
            $corpus = require __DIR__ . '/corpus/ocr-extraits.php';
            self::$corpus = $corpus;
        }

        return self::$corpus;
    }

    // ------------------------------------------------------------------ tests unitaires historiques

    public function testUnVraiTexteFrancaisEstLisible(): void
    {
        self::assertTrue(ExtraitsLisibles::lisible(
            'Facture AN-5001 du 12.01.2026, émetteur Atelier Numérique Sàrl, montant 4 244,74 CHF.'));
        self::assertTrue(ExtraitsLisibles::lisible(
            '… le présent contrat de maintenance court jusqu’au 31 décembre 2026 …'));
    }

    public function testUnBruitDeSymbolesNestPasLisible(): void
    {
        self::assertFalse(ExtraitsLisibles::lisible('@@@ x7#qZ |||| 8O0 l1I ~~~ ## /// \\\\\\\\ *'));
    }

    public function testUnBruitDeConsonnesNestPasLisible(): void
    {
        self::assertFalse(ExtraitsLisibles::lisible('bcdfg hjklm npqrs tvwxz bcdfg hjklm npqrs tvwxz'));
    }

    public function testUnExtraitTropCourtNestPasJugeLisible(): void
    {
        self::assertFalse(ExtraitsLisibles::lisible(''));
        self::assertFalse(ExtraitsLisibles::lisible('ab'));
    }

    public function testRetenirNeGardeQueLesExtraitsLisiblesEtBorneLeNombre(): void
    {
        $extraits = [
            [['t' => '@@@ 7#qZ |||| ~~~', 'hit' => true]],
            [['t' => 'Facture AN-5001 du 12.01.2026, émise par Atelier Numérique Sàrl.', 'hit' => true]],
            [['t' => 'bcdfg hjklm npqrs tvwxz bcdfg hjklm', 'hit' => false]],
            [['t' => 'Le contrat de maintenance court jusqu’au 31 décembre 2026.', 'hit' => false]],
            [['t' => 'Troisième extrait lisible mais au-delà de la borne.', 'hit' => false]],
        ];

        $gardes = ExtraitsLisibles::retenir($extraits, 2);

        self::assertCount(2, $gardes, 'la borne est respectée');
        self::assertSame('Facture AN-5001 du 12.01.2026, émise par Atelier Numérique Sàrl.', $gardes[0][0]['t']);
        self::assertSame('Le contrat de maintenance court jusqu’au 31 décembre 2026.', $gardes[1][0]['t']);
    }

    public function testRetenirConserveLesSegmentsEtLeursMarques(): void
    {
        $extraits = [[['t' => 'Contrat de ', 'hit' => false], ['t' => 'maintenance', 'hit' => true], ['t' => ' 2026.', 'hit' => false]]];

        $gardes = ExtraitsLisibles::retenir($extraits, 3);

        self::assertCount(1, $gardes);
        self::assertSame($extraits[0], $gardes[0], 'les segments et leurs surlignages sont intacts');
    }

    // ------------------------------------------------------------------ corpus OCR synthétique

    /** Chaque forme du corpus donne exactement l'extrait attendu (null = rien). */
    public function testLeCorpusOcrDonneLExtraitAttendu(): void
    {
        foreach (self::corpus() as $cas) {
            self::assertSame(
                $cas['attendu'],
                ExtraitsLisibles::nettoyer($cas['texte']),
                sprintf('forme « %s » (%s)', $cas['nom'], $cas['categorie']),
            );
        }
    }

    /** Zéro extrait bruité affiché : aucun fragment interdit n'apparaît, et un bruit seul ne rend rien. */
    public function testLeCorpusNeMontreAucunExtraitBruite(): void
    {
        foreach (self::corpus() as $cas) {
            $resultat = ExtraitsLisibles::nettoyer($cas['texte']);
            if ($cas['categorie'] === 'bruit') {
                self::assertNull($resultat, sprintf('bruit seul « %s » : rien ne doit s’afficher', $cas['nom']));
                continue;
            }
            self::assertNotNull($resultat, sprintf('forme utile « %s » : un extrait doit s’afficher', $cas['nom']));
            foreach ($cas['interdits'] as $interdit) {
                self::assertFalse(
                    self::porteInterdit((string) $resultat, $interdit),
                    sprintf('forme « %s » : le bruit « %s » est affiché', $cas['nom'], $interdit),
                );
            }
            self::assertStringContainsString($resultat, $cas['texte'], 'l’extrait affiché ne peut être inventé');
        }
    }

    /**
     * Un fragment interdit ne compte que comme MOT : « nn » ne doit pas se confondre avec les deux
     * n de « Lausanne ». Les interdits non alphabétiques sont cherchés tels quels.
     */
    private static function porteInterdit(string $resultat, string $interdit): bool
    {
        if (preg_match('/^\p{L}+$/u', $interdit) === 1) {
            return preg_match('/(?<![\p{L}])' . preg_quote($interdit, '/') . '(?![\p{L}])/u', $resultat) === 1;
        }

        return str_contains($resultat, $interdit);
    }

    /** Les extraits propres sont conservés (≥ 90 %) et jamais rognés à tort. */
    public function testLesExtraitsPropresSontConserves(): void
    {
        $propres = array_values(array_filter(
            self::corpus(),
            static fn (array $cas): bool => $cas['categorie'] === 'propre'));
        self::assertGreaterThanOrEqual(3, count($propres), 'le corpus porte assez d’extraits propres');

        $gardes = array_values(array_filter(
            $propres,
            static fn (array $cas): bool => ExtraitsLisibles::nettoyer($cas['texte']) !== null));

        $taux = count($gardes) / count($propres);
        self::assertGreaterThanOrEqual(0.9, $taux, sprintf('seulement %d/%d extraits propres conservés', count($gardes), count($propres)));

        foreach ($propres as $cas) {
            self::assertSame($cas['texte'], ExtraitsLisibles::nettoyer($cas['texte']), sprintf('extrait propre rogné à tort : « %s »', $cas['nom']));
        }
    }

    /** Le rognage au premier segment propre garde les segments et leurs surlignages. */
    public function testLeRognageConserveLesMarques(): void
    {
        $texte = 'nn NE {EL EE PostC.  Rue du Simplon 12, 1006 Lausanne';
        $extrait = [
            ['t' => 'nn NE {EL EE PostC.  Rue du ', 'hit' => false],
            ['t' => 'Simplon', 'hit' => true],
            ['t' => ' 12, 1006 Lausanne', 'hit' => false],
        ];

        $gardes = ExtraitsLisibles::retenir([$extrait], 3);

        self::assertCount(1, $gardes);
        $rendu = implode('', array_map(static fn (array $s): string => (string) $s['t'], $gardes[0]));
        self::assertSame('du Simplon 12, 1006 Lausanne', $rendu);
        $surligue = array_values(array_filter($gardes[0], static fn (array $s): bool => ($s['hit'] ?? false) === true));
        self::assertCount(1, $surligue, 'le terme trouvé reste surligné');
        self::assertSame('Simplon', $surligue[0]['t']);
    }

    /** Une forme bruitée mêlée à un vrai texte ne fait pas disparaître le document : l'extrait propre est rogné. */
    public function testUnExtraitMixteEstRognePasMasque(): void
    {
        $resultat = ExtraitsLisibles::nettoyer('nn NE {EL EE PostC.  Rue du Simplon 12, 1006 Lausanne');

        self::assertNotNull($resultat);
        self::assertStringStartsWith('du Simplon', (string) $resultat);
        self::assertFalse(self::porteInterdit((string) $resultat, 'nn'));
        self::assertStringNotContainsString('{', (string) $resultat);
    }
}
