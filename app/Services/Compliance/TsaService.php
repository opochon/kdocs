<?php

declare(strict_types=1);

/**
 * Horodatage qualifié TSA — GAP-023 (conformité P2 K-Docs).
 *
 * Construit une TimeStampRequest RFC 3161 en DER, l'envoie à la TSA via un transport
 * injectable (curl par défaut), puis stocke la réponse en base dans
 * `documents.tsa_token` (base64) et `documents.tsa_timestamped_at`.
 *
 * Hors périmètre de verify() : validation de la signature PKI de la TSA
 * (nécessiterait openssl_pkcs7_verify ou une lib ASN.1 complète). La méthode
 * effectue des vérifications structurelles hors-ligne (status granted, empreinte
 * présente) — suffisant pour la suite de tests unitaires hermétiques.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3161 RFC 3161 – Time-Stamp Protocol
 */

namespace KDocs\Services\Compliance;

use KDocs\Core\Database;

class TsaService
{
    private \PDO $db;

    /** @var callable(string $url, string $binaryTsq): string */
    private $transport;

    private string $tsaUrl;

    /**
     * @param \PDO|null     $db        Base de données injectable (SQLite en test).
     * @param callable|null $transport Transport injectable : fn(string $url, string $binaryTsq): string
     *                                 retourne la TimeStampResp binaire brute.
     *                                 Défaut = POST curl Content-Type: application/timestamp-query.
     * @param string|null   $tsaUrl    URL de la TSA (défaut = env('TSA_URL', '')).
     */
    public function __construct(
        ?\PDO $db = null,
        ?callable $transport = null,
        ?string $tsaUrl = null
    ) {
        $this->db        = $db ?? Database::getInstance();
        $this->transport = $transport ?? self::defaultTransport();
        $this->tsaUrl    = $tsaUrl ?? (string) env('TSA_URL', '');
    }

    /**
     * Horodate un document : construit la TSQ, appelle la TSA, stocke le token.
     *
     * Idempotent : si `tsa_token` est déjà présent, retourne le token existant
     * sans rappeler la TSA.
     *
     * @return array{token: string, timestamped_at: string}
     *
     * @throws \InvalidArgumentException Si le document n'existe pas.
     * @throws \RuntimeException         Si le transport échoue.
     */
    public function timestamp(int $documentId): array
    {
        $doc = $this->fetchDocument($documentId);
        if ($doc === null) {
            throw new \InvalidArgumentException("Document {$documentId} introuvable");
        }

        // Idempotence : token déjà présent → pas d'appel TSA
        if (!empty($doc['tsa_token'])) {
            return [
                'token'          => (string) $doc['tsa_token'],
                'timestamped_at' => (string) ($doc['tsa_timestamped_at'] ?? ''),
            ];
        }

        // Calcul de l'empreinte SHA-256 du document (binaire, 32 octets)
        $hash = $this->fingerprint($doc);

        // Construction de la TimeStampReq DER (RFC 3161 §2.4.2)
        $tsq = $this->buildTimeStampReq($hash);

        // Appel TSA via transport injectable (AUCUN réseau en test)
        $tspResp = ($this->transport)($this->tsaUrl, $tsq);

        // Stockage en base : base64 de la réponse brute + date UTC
        $b64Token = base64_encode($tspResp);
        $now      = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'UPDATE documents SET tsa_token = ?, tsa_timestamped_at = ? WHERE id = ?'
        );
        $stmt->execute([$b64Token, $now, $documentId]);

        return [
            'token'          => $b64Token,
            'timestamped_at' => $now,
        ];
    }

    /**
     * Vérification structurelle hors-ligne du token TSA.
     *
     * Contrôles effectués (sans réseau ni crypto PKI complète) :
     *  1. Token présent et base64 valide.
     *  2. Première séquence DER bien formée (tag 0x30).
     *  3. PKIStatus = granted (INTEGER 0, soit `\x02\x01\x00`) présent dans les
     *     16 premiers octets de la réponse (position attendue dans PKIStatusInfo).
     *  4. L'empreinte SHA-256 recalculée du document figure dans les octets décodés
     *     (garantit que le token couvre ce document et pas un autre).
     *
     * LIMITE : la signature de la TSA n'est pas vérifiée cryptographiquement.
     * Pour une vérification complète, utiliser `openssl ts -verify` ou une lib ASN.1.
     *
     * @return bool true si toutes les vérifications structurelles passent.
     */
    public function verify(int $documentId): bool
    {
        $doc = $this->fetchDocument($documentId);
        if ($doc === null || empty($doc['tsa_token'])) {
            return false;
        }

        $decoded = base64_decode((string) $doc['tsa_token'], strict: true);
        if ($decoded === false || strlen($decoded) < 10) {
            return false;
        }

        // Vérif 1 : doit être une SEQUENCE DER (tag 0x30)
        if (ord($decoded[0]) !== 0x30) {
            return false;
        }

        // Vérif 2 : PKIStatus granted = INTEGER(0) — format DER : 02 01 00
        // Apparaît dans les ~16 premiers octets de TimeStampResp > PKIStatusInfo
        if (!str_contains(substr($decoded, 0, 16), "\x02\x01\x00")) {
            return false;
        }

        // Vérif 3 : l'empreinte SHA-256 recalculée doit figurer dans le token
        // (vérifie que le token couvre bien ce document)
        $fingerprint = $this->fingerprint($doc);
        if (!str_contains($decoded, $fingerprint)) {
            return false;
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Méthodes privées
    // -------------------------------------------------------------------------

    /**
     * Calcule l'empreinte binaire SHA-256 du document (32 octets).
     *
     * Utilise la colonne `checksum` si disponible (hex SHA-256) ; sinon hash
     * de (id || title || created_at) pour rester simple et sans I/O fichier.
     *
     * @param array<string, mixed> $doc Ligne documents.
     *
     * @return string 32 octets binaires.
     */
    private function fingerprint(array $doc): string
    {
        if (!empty($doc['checksum'])) {
            // checksum stocké en hexadécimal dans la colonne (ex. sha256 du fichier)
            return (string) hex2bin((string) $doc['checksum']);
        }

        return hash(
            'sha256',
            (string) $doc['id'] . ($doc['title'] ?? '') . ($doc['created_at'] ?? ''),
            true // raw_output = binaire
        );
    }

    /**
     * Construit une TimeStampRequest DER minimale (RFC 3161 §2.4.2).
     *
     * Structure encodée :
     * ```
     * TimeStampReq ::= SEQUENCE {
     *   version        INTEGER { v1(1) },          -- 02 01 01
     *   messageImprint MessageImprint,
     *   certReq        BOOLEAN DEFAULT FALSE        -- 01 01 FF (TRUE)
     * }
     *
     * MessageImprint ::= SEQUENCE {
     *   hashAlgorithm AlgorithmIdentifier,          -- SHA-256 OID 2.16.840.1.101.3.4.2.1
     *   hashedMessage OCTET STRING                  -- 32 octets
     * }
     * ```
     *
     * Encodage DER de l'OID 2.16.840.1.101.3.4.2.1 :
     *   composante 0+1 : 2×40+16 = 0x60
     *   840            : 0x86 0x48  (840 = 6×128+72)
     *   1              : 0x01
     *   101            : 0x65
     *   3              : 0x03
     *   4              : 0x04
     *   2              : 0x02
     *   1              : 0x01
     *
     * @param string $hashBin 32 octets binaires (SHA-256).
     *
     * @return string TimeStampReq DER (≈ 59 octets).
     */
    private function buildTimeStampReq(string $hashBin): string
    {
        // OID SHA-256 : 2.16.840.1.101.3.4.2.1 (9 octets)
        $oidBytes = "\x60\x86\x48\x01\x65\x03\x04\x02\x01";

        // AlgorithmIdentifier ::= SEQUENCE { OID, NULL }
        $algOid = $this->tlv(0x06, $oidBytes);         // OID TLV        (11 octets)
        $null   = "\x05\x00";                          // NULL            (2 octets)
        $algId  = $this->tlv(0x30, $algOid . $null);  // AlgorithmId    (15 octets)

        // hashedMessage OCTET STRING (32 octets SHA-256)
        $hashOctet = $this->tlv(0x04, $hashBin);       // OCTET STRING   (34 octets)

        // MessageImprint ::= SEQUENCE { AlgorithmIdentifier, OCTET STRING }
        $msgImp = $this->tlv(0x30, $algId . $hashOctet); // MessageImprint (51 octets)

        // Champs obligatoires
        $version = "\x02\x01\x01";  // version INTEGER v1(1)
        $certReq = "\x01\x01\xff";  // certReq BOOLEAN TRUE

        // TimeStampReq ::= SEQUENCE { version, messageImprint, certReq }
        return $this->tlv(0x30, $version . $msgImp . $certReq);
    }

    /**
     * Encode un TLV DER (tag, longueur court-form ou long-form, valeur).
     *
     * @param int    $tag   Tag DER (1 octet).
     * @param string $value Valeur déjà encodée.
     *
     * @return string TLV encodé.
     */
    private function tlv(int $tag, string $value): string
    {
        $len = strlen($value);

        if ($len <= 0x7F) {
            // Forme courte (suffisant pour nos TSQ, < 127 octets)
            return chr($tag) . chr($len) . $value;
        }

        // Forme longue (défensive, pas utilisée pour les TSQ de ce service)
        $lenBytes = '';
        $tmp = $len;
        while ($tmp > 0) {
            $lenBytes = chr($tmp & 0xFF) . $lenBytes;
            $tmp >>= 8;
        }

        return chr($tag) . chr(0x80 | strlen($lenBytes)) . $lenBytes . $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDocument(int $documentId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM documents WHERE id = ?');
        $stmt->execute([$documentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Transport par défaut : POST curl vers la TSA.
     *
     * Non utilisé en test (transport injectable injecté à la place pour
     * garantir l'herméticité — aucun réseau dans la suite PHPUnit).
     *
     * @return callable(string, string): string
     */
    private static function defaultTransport(): callable
    {
        return static function (string $url, string $binaryTsq): string {
            if ($url === '') {
                throw new \RuntimeException('TSA_URL non configurée (env TSA_URL manquant)');
            }

            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('curl_init a échoué pour ' . $url);
            }

            curl_setopt_array($ch, [
                \CURLOPT_POST           => true,
                \CURLOPT_POSTFIELDS     => $binaryTsq,
                \CURLOPT_HTTPHEADER     => ['Content-Type: application/timestamp-query'],
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_TIMEOUT        => 10,
            ]);

            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                throw new \RuntimeException('Erreur cURL TSA : ' . $err);
            }

            return (string) $resp;
        };
    }
}
