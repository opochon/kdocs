<?php

declare(strict_types=1);

namespace Ged\Tests\Classement;

use Ged\Classement\ClientIa;
use Ged\Classement\ReponseIa;
use Ged\Classement\Regles;
use PHPUnit\Framework\TestCase;

/** Porte cumulative R2g : chaque défaut jugé R2c–R2f reste reproduit en données synthétiques. */
final class OracleClassementRegressionTest extends TestCase
{
    use SupportClassement;

    protected function setUp(): void
    {
        $this->setUpClassement();
    }

    public function testCorpusCumulatifNeCommetAucuneErreurDeTypeOuDEmetteur(): void
    {
        $corpus = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/corpus/classement-regression/verite.json'), true, 512, JSON_THROW_ON_ERROR);
        $reponses = [];
        foreach ($corpus['pieces'] as $piece) {
            $ia = $piece['ia'];
            $reponses[$piece['id']] = new ReponseIa($ia['type'], $ia['emetteur'], null, $ia['date'], null, [], (float) $ia['confiance'], 'r2g-simule');
        }
        $classeur = $this->classeur(new ClientIaRegression($reponses));
        $erreurs = [];
        foreach ($corpus['pieces'] as $piece) {
            $r = $classeur->proposer($piece['texte'], [], $piece['id'] . '.pdf');
            if (!Regles::memeType($piece['type'], $r['type'])
                || (($piece['emetteur'] ?? null) !== null
                    ? !Regles::memeType($piece['emetteur'], $r['emetteur'])
                    : $r['emetteur'] !== null)
                || $piece['date'] !== $r['date']
                || (isset($piece['titre']) && $piece['titre'] !== $r['titre'])) {
                $erreurs[] = $piece['id'];
            }
        }

        self::assertSame([], $erreurs, 'porte cumulative : 0 erreur');
    }
}

final class ClientIaRegression implements ClientIa
{
    /** @param array<string, ReponseIa> $reponses */
    public function __construct(private readonly array $reponses) {}
    public function classer(string $texte, string $nomFichier, array $typesConnus): ?ReponseIa
    {
        return $this->reponses[pathinfo($nomFichier, PATHINFO_FILENAME)] ?? null;
    }
    public function aExpire(): bool { return false; }
    public function disponible(): bool { return true; }
    public function modele(): string { return 'r2g-simule'; }
}
