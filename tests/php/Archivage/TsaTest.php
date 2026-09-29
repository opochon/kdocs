<?php

declare(strict_types=1);

namespace Ged\Tests\Archivage;

use Ged\Archivage\ErreurScellement;
use Ged\Archivage\HorlogeFigee;
use Ged\Archivage\TsaReelle;
use Ged\Archivage\TsaSimulee;
use PHPUnit\Framework\TestCase;

/**
 * Oracle TSA (clause 1 du lot V2-09) : le client RFC 3161 construit une TimeStampReq DER vérifiable, la
 * TSA simulée produit un jeton structurellement valable, et une TSA injoignable ne produit jamais un
 * scellement silencieux. Aucun réseau : le transport est injecté.
 */
final class TsaTest extends TestCase
{
    /** L'empreinte binaire de 32 octets d'un contenu synthétique. */
    private function empreinte(): string
    {
        return hash('sha256', 'document synthétique à horodater', true);
    }

    public function testTsaReelleConstruitUneRequeteRfc3161(): void
    {
        $tsq = TsaReelle::requete($this->empreinte());

        self::assertSame("\x30", $tsq[0], 'la TimeStampReq doit être une SEQUENCE DER');
        self::assertSame("\x02\x01\x01", substr($tsq, 2, 3), 'version v1 attendue');
        // OID SHA-256 2.16.840.1.101.3.4.2.1 présent dans l'AlgorithmIdentifier.
        self::assertStringContainsString("\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01", $tsq);
        self::assertStringContainsString($this->empreinte(), $tsq, 'l\'empreinte doit être dans le MessageImprint');
    }

    public function testTsaReelleEnvoieLaRequeteParLeTransportEtRendLaReponse(): void
    {
        $recu = null;
        $reponse = TsaSimulee::reponseDepuis($this->empreinte(), 1775347200);
        $tsa = new TsaReelle('https://tsa.example.test/', function (string $url, string $tsq) use (&$recu, $reponse): string {
            $recu = [$url, $tsq];

            return $reponse;
        });

        self::assertSame($reponse, $tsa->horodater($this->empreinte()));
        self::assertIsArray($recu);
        self::assertSame('https://tsa.example.test/', $recu[0]);
        self::assertSame(TsaReelle::requete($this->empreinte()), $recu[1]);
    }

    public function testTsaReelleSansUrlSeNomme(): void
    {
        $tsa = new TsaReelle('');

        try {
            $tsa->horodater($this->empreinte());
            self::fail('une TSA sans URL doit refuser, jamais sceller en silence');
        } catch (ErreurScellement $e) {
            self::assertStringContainsString('TSA_URL', $e->getMessage());
        }
    }

    public function testTsaReelleRefuseUneReponseIllisible(): void
    {
        $tsa = new TsaReelle('https://tsa.example.test/', static fn (string $url, string $tsq): string => 'erreur HTTP 500 en clair');

        try {
            $tsa->horodater($this->empreinte());
            self::fail('une réponse non DER doit être refusée');
        } catch (ErreurScellement $e) {
            self::assertStringContainsString('illisible', $e->getMessage());
        }
    }

    public function testTsaSimuleeProduitUnJetonVerifiable(): void
    {
        $horloge = HorlogeFigee::depuis('2026-04-01 08:00:00');
        $tsa = new TsaSimulee($horloge);
        $jeton = $tsa->horodater($this->empreinte());

        self::assertSame("\x30", $jeton[0], 'la réponse TSA est une SEQUENCE DER');
        self::assertStringContainsString("\x02\x01\x00", substr($jeton, 0, 16), 'statut granted (0) attendu');
        self::assertStringContainsString($this->empreinte(), $jeton, 'le jeton couvre l\'empreinte horodatée');
        self::assertSame('TSA simulée', $tsa->etiquette());
    }

    public function testLaTsaSimuleeNeCouvrePasUneAutreEmpreinte(): void
    {
        $jeton = (new TsaSimulee(HorlogeFigee::depuis('2026-04-01 08:00:00')))->horodater($this->empreinte());

        self::assertStringNotContainsString(hash('sha256', 'autre contenu', true), $jeton);
    }
}
