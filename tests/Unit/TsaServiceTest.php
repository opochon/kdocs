<?php

declare(strict_types=1);

namespace Tests\Unit;

use KDocs\Services\Compliance\TsaService;
use PHPUnit\Framework\TestCase;

/**
 * GAP-023 — Horodatage qualifié TSA : tsa_token non null + validation RFC 3161.
 *
 * Hermétique : SQLite en mémoire + faux transport (AUCUN réseau).
 * Le faux transport capture la TimeStampReq envoyée et retourne une fausse
 * TimeStampResp DER structurellement valide (status granted + messageImprint).
 */
class TsaServiceTest extends TestCase
{
    private \PDO $db;

    /** @var string Empreinte SHA-256 binaire du document de test (calculée à l'avance) */
    private string $expectedHash;

    /** @var string Fausse TimeStampResp DER utilisée par le transport mock */
    private string $fakeResponse;

    /** @var string|null Dernière TSQ binaire reçue par le transport mock */
    private ?string $capturedTsq = null;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Schéma minimal documents + colonnes TSA
        $this->db->exec('CREATE TABLE documents (
            id                 INTEGER PRIMARY KEY,
            title              TEXT,
            created_at         TEXT,
            checksum           TEXT,
            tsa_token          TEXT,
            tsa_timestamped_at TEXT
        )');

        $this->db->exec("
            INSERT INTO documents (id, title, created_at)
            VALUES (42, 'Facture fournisseur', '2026-01-16 08:00:00')
        ");

        // Pré-calcul de l'empreinte : hash('sha256', id . title . created_at, true)
        $this->expectedHash = hash(
            'sha256',
            '42' . 'Facture fournisseur' . '2026-01-16 08:00:00',
            true
        );

        // Fausse TimeStampResp DER minimale :
        //   SEQUENCE {
        //     SEQUENCE { INTEGER 0 }   ← PKIStatusInfo : status granted (02 01 00)
        //     OCTET STRING [hash]      ← messageImprint embarqué pour verify()
        //   }
        //
        // Octets :
        //   30 27              ← outer SEQUENCE (39 octets de contenu)
        //     30 03            ← PKIStatusInfo SEQUENCE (3 octets)
        //       02 01 00       ← INTEGER 0 = granted
        //     04 20 [32 b]     ← OCTET STRING hash (34 octets)
        //
        // Vérification des longueurs :
        //   PKIStatusInfo   = 2 + 3 = 5 octets
        //   OCTET STRING    = 2 + 32 = 34 octets
        //   Contenu total   = 5 + 34 = 39 = 0x27
        $pkiStatus   = "\x30\x03\x02\x01\x00";           // SEQUENCE { INTEGER 0 }
        $hashOctet   = "\x04\x20" . $this->expectedHash;  // OCTET STRING [32 bytes]
        $content     = $pkiStatus . $hashOctet;            // 5 + 34 = 39 bytes
        $this->fakeResponse = "\x30\x27" . $content;       // SEQUENCE (39 bytes)
    }

    protected function tearDown(): void
    {
        $this->capturedTsq = null;
        // SQLite in-memory : nettoyage automatique à la destruction du PDO
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Crée un TsaService avec faux transport capturant la TSQ.
     */
    private function makeService(): TsaService
    {
        $fakeResponse = $this->fakeResponse;
        $capturedRef  = &$this->capturedTsq;

        $transport = static function (string $url, string $tsq) use ($fakeResponse, &$capturedRef): string {
            $capturedRef = $tsq;
            return $fakeResponse;
        };

        return new TsaService($this->db, $transport, 'http://tsa.test/');
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Après timestamp(), tsa_token doit être non null et tsa_timestamped_at renseigné.
     */
    public function testTimestampStockeLeTokenNonNull(): void
    {
        $service = $this->makeService();
        $result  = $service->timestamp(42);

        // Valeur de retour
        $this->assertNotEmpty($result['token'], 'token retourné ne doit pas être vide');
        $this->assertNotEmpty($result['timestamped_at'], 'timestamped_at retourné ne doit pas être vide');

        // Persistance en base
        $row = $this->db->query('SELECT tsa_token, tsa_timestamped_at FROM documents WHERE id = 42')
                        ->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotNull($row['tsa_token'], 'tsa_token doit être non null après timestamp()');
        $this->assertNotEmpty($row['tsa_timestamped_at'], 'tsa_timestamped_at doit être renseigné');

        // Le token en base est du base64 valide décryptant vers la fausse réponse
        $this->assertSame(
            base64_encode($this->fakeResponse),
            $row['tsa_token']
        );
    }

    /**
     * timestamp() est idempotent : un second appel retourne le même token sans rappeler la TSA.
     */
    public function testTimestampEstIdempotent(): void
    {
        $callCount = 0;
        $fakeResp  = $this->fakeResponse;

        $transport = static function (string $url, string $tsq) use ($fakeResp, &$callCount): string {
            $callCount++;
            return $fakeResp;
        };

        $service = new TsaService($this->db, $transport, 'http://tsa.test/');

        $first  = $service->timestamp(42);
        $second = $service->timestamp(42);

        $this->assertSame($first['token'], $second['token'], 'Le token doit être identique au second appel');
        $this->assertSame(1, $callCount, 'Le transport ne doit être appelé qu\'une seule fois');
    }

    /**
     * verify() doit retourner true sur un token valide stocké par timestamp().
     */
    public function testVerifyRetourneTrueSurTokenValide(): void
    {
        $service = $this->makeService();
        $service->timestamp(42);

        $this->assertTrue($service->verify(42));
    }

    /**
     * verify() doit retourner false si le contenu du document a changé
     * (l'empreinte recalculée ne correspond plus à celle dans le token).
     */
    public function testVerifyRetourneFalseSiContenuChange(): void
    {
        $service = $this->makeService();
        $service->timestamp(42);

        // Modifier le titre du document → l'empreinte change
        $this->db->exec("UPDATE documents SET title = 'Titre modifié XYZ' WHERE id = 42");

        $this->assertFalse(
            $service->verify(42),
            'verify() doit retourner false si le document a été modifié après horodatage'
        );
    }

    /**
     * verify() doit retourner false si le tsa_token est altéré.
     */
    public function testVerifyRetourneFalseSiTokenAltere(): void
    {
        $service = $this->makeService();
        $service->timestamp(42);

        // Remplacer le token par un blob valide mais avec un hash erroné (32 zéros)
        $fakeWrongHash = "\x30\x27\x30\x03\x02\x01\x00\x04\x20" . str_repeat("\x00", 32);
        $this->db->prepare('UPDATE documents SET tsa_token = ? WHERE id = 42')
                 ->execute([base64_encode($fakeWrongHash)]);

        $this->assertFalse(
            $service->verify(42),
            'verify() doit retourner false si le token est altéré (mauvais hash)'
        );
    }

    /**
     * verify() doit retourner false si le token est du base64 invalide.
     */
    public function testVerifyRetourneFalseSurBase64Invalide(): void
    {
        $service = $this->makeService();
        $this->db->exec("UPDATE documents SET tsa_token = 'not-valid-base64!!!!' WHERE id = 42");

        $this->assertFalse($service->verify(42));
    }

    /**
     * verify() doit retourner false si aucun token n'a encore été stocké.
     */
    public function testVerifyRetourneFalseSiPasDeToken(): void
    {
        $service = $this->makeService();
        $this->assertFalse($service->verify(42));
    }

    /**
     * La TimeStampReq envoyée au transport doit contenir l'OID SHA-256
     * (2.16.840.1.101.3.4.2.1 encodé en DER : 06 09 60 86 48 01 65 03 04 02 01).
     */
    public function testTimeStampReqContientOidSha256(): void
    {
        $service = $this->makeService();
        $service->timestamp(42);

        $this->assertNotNull($this->capturedTsq, 'Le transport doit avoir reçu une TSQ');

        // OID 2.16.840.1.101.3.4.2.1 en TLV DER
        $oidTlv = "\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01";

        $this->assertStringContainsString(
            $oidTlv,
            $this->capturedTsq,
            'La TSQ doit contenir l\'OID SHA-256 (2.16.840.1.101.3.4.2.1) en DER'
        );
    }

    /**
     * La TSQ doit commencer par une SEQUENCE DER (tag 0x30).
     */
    public function testTimeStampReqEstUneSequenceDer(): void
    {
        $service = $this->makeService();
        $service->timestamp(42);

        $this->assertNotNull($this->capturedTsq);
        $this->assertSame(0x30, ord($this->capturedTsq[0]), 'La TSQ doit commencer par le tag SEQUENCE DER (0x30)');
    }

    /**
     * timestamp() doit lever InvalidArgumentException pour un document inexistant.
     */
    public function testTimestampDocumentInconnuLeveException(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->timestamp(9999);
    }
}
