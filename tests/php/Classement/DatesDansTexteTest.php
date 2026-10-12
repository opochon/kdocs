<?php

declare(strict_types=1);

namespace Ged\Tests\Classement;

use Ged\Classement\Regles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lecture d'une date dans TOUT le texte d'une pièce, dans l'esprit du `parse_date` de Paperless-ngx :
 * première forme reconnue et plausible, mois FR/DE/IT/EN, ordinaux anglais, pas de nombre collé.
 * Les 25 vecteurs complets de Paperless sont rejoués par tests/php/Parite/ComportementTest.php.
 */
final class DatesDansTexteTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function dates(): array
    {
        return [
            'jour.mois.annee' => ['lorem ipsum 13.02.2018 lorem ipsum', '2018-02-13'],
            'premiere date du texte' => ['13.02.2018 puis 05.03.2019', '2018-02-13'],
            'jour/mois/annee' => ['Customer Number Currency 22/MAR/2022 Credit Card', '2022-03-22'],
            'jour-mois-annee' => ['Customer Number Currency 22-MAR-2022 Credit Card', '2022-03-22'],
            'jour mois annee' => ['Customer Number Currency 22 MAR 2022 Credit Card', '2022-03-22'],
            'ordinal anglais' => ['Customer Number Currency 21st MAR 2022 Credit Card', '2022-03-21'],
            'ordinal et mois en toutes lettres' => ['Customer Number Currency 22nd March 2022 Credit Card', '2022-03-22'],
            'mois puis jour' => ['CHASE 0 September 25, 2019 JPMorgan Chase Bank', '2019-09-25'],
            'mois francais' => ['Modeleville, le 5 novembre 2010', '2010-11-05'],
            'mois allemand' => ["Wohnort\nMärz 2020", '2020-03-01'],
            'mois inconnu puis mois allemand' => ["27. Nullmonth 2020\nMärz 2020", '2020-03-01'],
            'mois italien' => ['Città modello, il 9 ottobre 2012', '2012-10-09'],
            'nombre a six chiffres' => ['lorem ipsum 130218 lorem ipsum', null],
            'nombre a huit chiffres' => ['lorem ipsum 20180213 lorem ipsum', null],
            'annee seule' => ['lorem ipsum 2018 lorem ipsum', null],
            'annee sur deux chiffres avec mois en lettres' => ['Customer Number Currency 22.MAR.22', null],
            'virgules dans la date' => ['Customer Number Currency 22,MAR,2022', null],
            'iban et bics ne sont pas des dates' => [
                "lorem ipsum\nWohnort\n3100\nIBAN\nAT87 4534\n1234\n1234 5678\nBIC\nlorem ipsum",
                null,
            ],
            'date hors plage passee' => ['01-07-0590 00:00:00', null],
            'date hors plage future' => ['01-07-2350 00:00:00', null],
        ];
    }

    #[DataProvider('dates')]
    public function testDateDansLeTexte(string $texte, ?string $attendue): void
    {
        self::assertSame($attendue, Regles::dateDansTexte($texte));
    }
}
