<?php

declare(strict_types=1);

namespace Ged\Tests\Classement;

use Ged\Classement\ClientIa;
use Ged\Classement\ClientIaNul;
use Ged\Classement\ReponseIa;
use Ged\Classement\Regles;
use PHPUnit\Framework\TestCase;

/**
 * Porte R2 : les faux en-têtes OCR et les dates historiques ne deviennent jamais des métadonnées
 * validées. Le corpus est synthétique et l'IA est figée : cette porte est déterministe, hors réseau.
 */
final class OracleClassementBruiteTest extends TestCase
{
    use SupportClassement;

    protected function setUp(): void
    {
        $this->setUpClassement();
    }

    public function testCorpusBruiteAvecIaNeValideAucunePieceFausse(): void
    {
        $corpus = $this->corpus();
        $reponses = [];
        foreach ($corpus['pieces'] as $piece) {
            $ia = $piece['ia'];
            $reponses[$piece['id']] = new ReponseIa($ia['type'], $ia['emetteur'], null, $ia['date'], null, [],
                (float) $ia['confiance'], 'simule-bruite');
        }
        $classeur = $this->classeur(new ClientIaParPiece($reponses));
        self::assertGreaterThanOrEqual(40, count($corpus['pieces']), 'corpus de scans réalistes suffisamment large');
        $types = array_values(array_unique(array_map(static fn (array $p): string => (string) $p['type'], $corpus['pieces'])));
        self::assertGreaterThanOrEqual(12, count($types), 'les douze types sont représentés');

        $validesFaux = 0;
        $emetteursPoubelle = 0;
        $datesHorsFenetre = 0;
        $emetteursVides = 0;
        $factures = 0;
        $datesVidesFactures = 0;

        foreach ($corpus['pieces'] as $piece) {
            $r = $classeur->proposer($piece['texte'], [], $piece['id'] . '.pdf');
            self::assertTrue(Regles::memeType((string) $piece['type'], (string) $r['type']), $piece['id'] . ' : type proposé');
            self::assertSame($piece['emetteur'] === null, $r['emetteur'] === null, $piece['id'] . ' : émetteur crédible ou vide');
            if ($piece['emetteur'] !== null) {
                self::assertTrue(Regles::memeType((string) $piece['emetteur'], (string) $r['emetteur']),
                    $piece['id'] . ' : émetteur canonique');
            }
            self::assertSame($piece['date'], $r['date'], $piece['id'] . ' : date d’émission crédible ou vide');
            self::assertSame($piece['valide'], $r['valide'], $piece['id'] . ' : statut attendu');

            $valideFaux = $r['valide'] && (!Regles::memeType($r['type'], $piece['type'])
                || !Regles::memeType($r['emetteur'], $piece['emetteur']) || $r['date'] !== $piece['date']);
            $validesFaux += (int) $valideFaux;
            $emetteursPoubelle += (int) ($r['emetteur'] !== null && in_array($r['emetteur'],
                ['Post CHAG', 'KKK KK', 'Adresse principal:', 'Pe a', 'La Banque se réserve le droit de prélever des frais de rappel.'], true));
            $datesHorsFenetre += (int) ($r['date'] !== null && !Regles::datePlausible($r['date']));
            $emetteursVides += (int) ($r['emetteur'] === null);
            $factures += (int) Regles::memeType($piece['type'], 'Facture');
            $datesVidesFactures += (int) (Regles::memeType($piece['type'], 'Facture') && $r['date'] === null);
        }

        self::assertSame(0, $validesFaux, '0 validé faux');
        self::assertSame(0, $emetteursPoubelle, '0 émetteur poubelle');
        self::assertSame(0, $datesHorsFenetre, '0 date hors fenêtre');
        self::assertLessThanOrEqual(0.15, $emetteursVides / count($corpus['pieces']), 'émetteurs vides ≤ 15 %');
        self::assertLessThanOrEqual(0.15, $datesVidesFactures / $factures, 'dates vides des factures ≤ 15 %');
    }

    public function testCorpusBruiteSansIaNeValideAucunePieceSeule(): void
    {
        $valides = 0;
        foreach ($this->corpus()['pieces'] as $piece) {
            $r = $this->classeur(new ClientIaNul())->proposer($piece['texte'], [], $piece['id'] . '.pdf');
            $valides += (int) $r['valide'];
        }

        self::assertSame(0, $valides, 'sans IA corroborante ni habitude, aucune pièce bruitée ne se valide seule');
    }

    /** @return array{pieces:list<array<string, mixed>>} */
    private function corpus(): array
    {
        $fichier = dirname(__DIR__, 2) . '/corpus/classement-bruite/verite.json';

        return json_decode((string) file_get_contents($fichier), true, 512, JSON_THROW_ON_ERROR);
    }
}

/** Client déterministe : la réponse dépend du nom de fichier de la pièce, pas du réseau. */
final class ClientIaParPiece implements ClientIa
{
    /** @param array<string, ReponseIa> $reponses */
    public function __construct(private readonly array $reponses)
    {
    }

    public function classer(string $texte, string $nomFichier, array $typesConnus): ?ReponseIa
    {
        return $this->reponses[pathinfo($nomFichier, PATHINFO_FILENAME)] ?? null;
    }

    public function aExpire(): bool { return false; }
    public function disponible(): bool { return true; }
    public function modele(): string { return 'simule-bruite'; }
}
