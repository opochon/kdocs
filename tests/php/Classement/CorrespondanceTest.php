<?php

declare(strict_types=1);

namespace Ged\Tests\Classement;

use Ged\Classement\Correspondance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le service de correspondance rejoue les algorithmes de Paperless-ngx 2.20 (`documents.matching`).
 * Les vecteurs de parité complets vivent dans tests/parite/vecteurs/ et sont rejoués par
 * ComportementTest ; ici on fixe le contrat, y compris la sensibilité à la casse que Paperless porte
 * dans `is_insensitive` et que l'extraction des vecteurs ne conserve pas.
 */
final class CorrespondanceTest extends TestCase
{
    public function testUnMotifVideNeCorrespondJamais(): void
    {
        foreach ([
            Correspondance::AUCUN,
            Correspondance::TOUS,
            Correspondance::UN,
            Correspondance::LITTERAL,
            Correspondance::REGEX,
            Correspondance::FLOU,
            Correspondance::AUTO,
        ] as $algorithme) {
            self::assertFalse(Correspondance::correspond($algorithme, '', 'du texte', false), $algorithme);
            self::assertFalse(Correspondance::correspond($algorithme, '   ', 'du texte', false), $algorithme);
        }
    }

    public function testMatchNoneEtMatchAutoNeCorrespondentJamais(): void
    {
        self::assertFalse(Correspondance::correspond(Correspondance::AUCUN, 'alpha', 'alpha', false));
        self::assertFalse(Correspondance::correspond(Correspondance::AUTO, 'alpha', 'alpha', false));
        self::assertFalse(Correspondance::correspond('MATCH_INCONNU', 'alpha', 'alpha', false));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function matchAll(): array
    {
        return [
            'tous presents' => ['alpha charlie gamma', 'I have alpha, charlie, and gamma in me', true],
            'un seul present' => ['alpha charlie gamma', 'I have alpha in me', false],
            'mot entier seulement' => ['12 34 56', 'I have 120, 34, and 56 in me', false],
            'nombre colle' => ['12 34 56', 'I have 123456 in me', false],
            'groupe entre guillemets et espaces multiples' => [
                'brown fox "lazy dogs"',
                'the quick brown fox jumped over the lazy  dogs',
                true,
            ],
            'groupe entre guillemets separe par ponctuation' => [
                'brown fox "lazy dogs"',
                'the quick brown fox jumped over the lazy... dogs',
                false,
            ],
            'ordre indifferent' => ['alpha charlie gamma', 'I have gamma, charlie, and alpha in me', true],
            'casse differente' => ['alpha charlie gamma', 'I have Alpha, charlie, and gamma in me', false],
        ];
    }

    #[DataProvider('matchAll')]
    public function testMatchAll(string $motif, string $texte, bool $attendu): void
    {
        self::assertSame($attendu, Correspondance::correspond(Correspondance::TOUS, $motif, $texte, false));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function matchAny(): array
    {
        return [
            'un mot suffit' => ['alpha charlie gamma', 'I have charlie in me', true],
            'aucun mot' => ['alpha charlie gamma', 'I have bravo in me', false],
            'mot entier seulement' => ['12 34 56', 'I have 120, 34, and 56 in me', true],
            'nombre colle' => ['12 34 56', 'I have 123456 in me', false],
            'groupe entre guillemets' => ['"brown fox" " lazy  dogs "', 'the quick brown fox', true],
            'groupe entre guillemets absent' => [
                '"brown fox" " lazy  dogs "',
                'the lazy fox jumped over the brown dogs',
                false,
            ],
            'casse differente' => ['alpha charlie gamma', 'I have chaRLie in me', false],
        ];
    }

    #[DataProvider('matchAny')]
    public function testMatchAny(string $motif, string $texte, bool $attendu): void
    {
        self::assertSame($attendu, Correspondance::correspond(Correspondance::UN, $motif, $texte, false));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function matchLiteral(): array
    {
        return [
            'chaine entiere presente' => ['alpha charlie gamma', "I have 'alpha charlie gamma' in me", true],
            'mots separes' => ['alpha charlie gamma', 'I have alpha, charlie, and gamma in me', false],
            'chaine numerique' => ['12 34 56', 'I have 12 34 56 in me', true],
            'chaine numerique coupee' => ['12 34 56', 'I have 12 34, and 56 in me', false],
            'casse differente' => ['alpha charlie gamma', "I have 'Alpha charlie gamma' in me", false],
        ];
    }

    #[DataProvider('matchLiteral')]
    public function testMatchLiteral(string $motif, string $texte, bool $attendu): void
    {
        self::assertSame($attendu, Correspondance::correspond(Correspondance::LITTERAL, $motif, $texte, false));
    }

    public function testLeModeInsensibleIgnoreLaCasse(): void
    {
        self::assertFalse(Correspondance::correspond(Correspondance::TOUS, 'alpha', 'I have Alpha in me', false));
        self::assertTrue(Correspondance::correspond(Correspondance::TOUS, 'alpha', 'I have Alpha in me', true));
        self::assertFalse(Correspondance::correspond(Correspondance::LITTERAL, 'alpha gamma', "I have 'Alpha Gamma'", false));
        self::assertTrue(Correspondance::correspond(Correspondance::LITTERAL, 'alpha gamma', "I have 'Alpha Gamma'", true));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function matchRegex(): array
    {
        return [
            'motif present' => ['alpha\w+gamma', 'I have alpha_and_gamma in me', true],
            'espace ne convient pas a \w' => ['alpha\w+gamma', 'I have alpha and gamma in me', false],
            'motif invalide' => ['[', "Don't match this", false],
            'casse differente' => ['alpha\w+gamma', 'I have Alpha_and_Gamma in me', false],
        ];
    }

    #[DataProvider('matchRegex')]
    public function testMatchRegex(string $motif, string $texte, bool $attendu): void
    {
        self::assertSame($attendu, Correspondance::correspond(Correspondance::REGEX, $motif, $texte, false));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function matchFuzzy(): array
    {
        return [
            'lettre manquante en fin de fenetre' => ['Springfield, Miss.', '1220 Main Street, Springf eld, Miss.', true],
            'espace au milieu du mot' => ['Springfield, Miss.', '1220 Main Street, Spring field, Miss.', true],
            'lettre manquante' => ['Springfield, Miss.', '1220 Main Street, Springfeld, Miss.', true],
            'exact' => ['Springfield, Miss.', '1220 Main Street Springfield Miss', true],
            'etat different' => ['Springfield, Miss.', '1220 Main Street, Springfield, Mich.', false],
        ];
    }

    #[DataProvider('matchFuzzy')]
    public function testMatchFuzzy(string $motif, string $texte, bool $attendu): void
    {
        self::assertSame($attendu, Correspondance::correspond(Correspondance::FLOU, $motif, $texte, true));
    }
}
