<?php

declare(strict_types=1);

namespace Tests\Unit;

use KDocs\Services\ClamAvScanner;
use PHPUnit\Framework\TestCase;

/**
 * GAP-045 — Antivirus ClamAV : ClamAvScanner::scan() bloque un fixture EICAR.
 *
 * Hermétique : faux transport injectable (AUCUN socket clamd réel).
 * La chaîne EICAR est construite par concaténation pour éviter que les AV
 * locaux flaguent le fichier source du test.
 */
class ClamAvScannerTest extends TestCase
{
    /** @var string Chemin du fichier EICAR temporaire */
    private string $eicarFile;

    protected function setUp(): void
    {
        // Note : le contenu EICAR réel déclencherait Windows Defender avant que fopen()
        // puisse l'ouvrir (TOCTOU entre is_file et fopen). Comme le transport est mocké,
        // le contenu du fichier est sans importance pour le comportement testé.
        // La chaîne EICAR serait : 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$' . 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
        // mais on utilise un contenu neutre pour le fichier temporaire.
        $this->eicarFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eicar_test_' . uniqid() . '.txt';
        file_put_contents($this->eicarFile, 'FAKE_EICAR_TEST_CONTENT_FOR_UNIT_TESTS');
    }

    protected function tearDown(): void
    {
        // Nettoyage du fichier temporaire (requis par phpunit beStrictAboutOutputDuringTests)
        if (is_file($this->eicarFile)) {
            @unlink($this->eicarFile);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Crée un ClamAvScanner activé avec un faux transport retournant $response.
     */
    private function makeScanner(string $response): ClamAvScanner
    {
        $transport = static fn(string $payload): string => $response;
        return new ClamAvScanner($transport, '127.0.0.1', 3310, true);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Quand clamd retourne FOUND, scan() doit retourner clean=false et le nom de la signature.
     */
    public function testScanFichierInfecteRetourneNonClean(): void
    {
        $scanner = $this->makeScanner("stream: Eicar-Test-Signature FOUND\n");
        $result  = $scanner->scan($this->eicarFile);

        $this->assertFalse($result['clean'], 'Un fichier EICAR doit être détecté comme non clean');
        $this->assertSame('Eicar-Test-Signature', $result['signature']);
        $this->assertFalse($result['skipped']);
    }

    /**
     * Quand clamd retourne OK, scan() doit retourner clean=true.
     */
    public function testScanFichierPropreRetourneClean(): void
    {
        $scanner = $this->makeScanner("stream: OK\n");
        $result  = $scanner->scan($this->eicarFile);

        $this->assertTrue($result['clean']);
        $this->assertNull($result['signature']);
        $this->assertFalse($result['skipped']);
    }

    /**
     * Quand ClamAV est désactivé, scan() retourne skipped=true et clean=true
     * (pas de régression sur l'upload).
     */
    public function testScanDesactiveRetourneSkipped(): void
    {
        // Scanner désactivé : enabled=false
        $transport = static fn(string $p): string => 'stream: OK';
        $scanner   = new ClamAvScanner($transport, '127.0.0.1', 3310, false);

        $result = $scanner->scan($this->eicarFile);

        $this->assertTrue($result['clean']);
        $this->assertTrue($result['skipped']);
        $this->assertNull($result['signature']);
    }

    /**
     * Régression 2026-07-03 : le constructeur PAR DÉFAUT (aucun argument, chemin
     * du hook apiUpload) instanciait le transport avant host/port → \Error
     * « uninitialized non-nullable property » → upload en 500.
     */
    public function testConstructeurParDefautNeLevePasDErreur(): void
    {
        $scanner = new ClamAvScanner();

        // Sans CLAMAV_ENABLED posé, le scan est ignoré (fail-open, pas de socket).
        $this->assertFalse($scanner->isEnabled());
        $result = $scanner->scan($this->eicarFile);
        $this->assertTrue($result['skipped']);
    }

    /**
     * isEnabled() reflète l'état passé au constructeur.
     */
    public function testIsEnabledRefleteEtat(): void
    {
        $t  = static fn(string $p): string => '';
        $on  = new ClamAvScanner($t, '127.0.0.1', 3310, true);
        $off = new ClamAvScanner($t, '127.0.0.1', 3310, false);

        $this->assertTrue($on->isEnabled());
        $this->assertFalse($off->isEnabled());
    }

    /**
     * scan() sur un chemin inexistant doit lever InvalidArgumentException.
     */
    public function testFichierIntrouvableLanceException(): void
    {
        $scanner = $this->makeScanner("stream: OK\n");

        $this->expectException(\InvalidArgumentException::class);
        $scanner->scan('/chemin/inexistant_' . uniqid() . '.txt');
    }

    /**
     * Le payload envoyé à clamd doit :
     *   - commencer par "zINSTREAM\0"
     *   - se terminer par 4 octets zéro (chunk terminal INSTREAM)
     */
    public function testFramingINSTREAMPayload(): void
    {
        $capturedPayload = null;

        $transport = static function (string $payload) use (&$capturedPayload): string {
            $capturedPayload = $payload;
            return "stream: OK\n";
        };

        $scanner = new ClamAvScanner($transport, '127.0.0.1', 3310, true);
        $scanner->scan($this->eicarFile);

        $this->assertNotNull($capturedPayload, 'Le transport doit avoir reçu un payload');

        // Préfixe zINSTREAM\0
        $this->assertStringStartsWith(
            "zINSTREAM\0",
            $capturedPayload,
            'Le payload INSTREAM doit commencer par "zINSTREAM\\0"'
        );

        // Chunk terminal : 4 octets zéro
        $this->assertStringEndsWith(
            "\x00\x00\x00\x00",
            $capturedPayload,
            'Le payload INSTREAM doit se terminer par 4 octets zéro (chunk terminal)'
        );
    }

    /**
     * La longueur du premier chunk doit être encodée en 4 octets big-endian.
     */
    public function testChunkLongueurBigEndian(): void
    {
        $capturedPayload = null;

        $transport = static function (string $payload) use (&$capturedPayload): string {
            $capturedPayload = $payload;
            return "stream: OK\n";
        };

        $scanner = new ClamAvScanner($transport, '127.0.0.1', 3310, true);
        $scanner->scan($this->eicarFile);

        // Après "zINSTREAM\0" (10 octets), on attend les 4 octets big-endian de la taille du premier chunk
        $prefix     = "zINSTREAM\0";
        $afterCmd   = substr($capturedPayload, strlen($prefix));
        $chunkLen   = unpack('N', substr($afterCmd, 0, 4))[1];
        $fileSize   = filesize($this->eicarFile);

        $this->assertSame(
            $fileSize,
            $chunkLen,
            "La longueur du premier chunk ({$chunkLen}) doit correspondre à la taille du fichier ({$fileSize})"
        );
    }

    /**
     * La signature extraite doit correspondre exactement à ce que clamd retourne.
     */
    public function testScanRetourneNomSignature(): void
    {
        $scanner = $this->makeScanner("stream: Win.Test.EICAR_HDB-1 FOUND\n");
        $result  = $scanner->scan($this->eicarFile);

        $this->assertFalse($result['clean']);
        $this->assertSame('Win.Test.EICAR_HDB-1', $result['signature']);
    }

    /**
     * Une réponse ERROR de clamd doit lever une RuntimeException (fail-open côté appelant).
     */
    public function testReponseErrorLeveException(): void
    {
        $scanner = $this->makeScanner("stream: ERROR: lstat() failed\n");

        $this->expectException(\RuntimeException::class);
        $scanner->scan($this->eicarFile);
    }
}
