<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * Client RFC 3161 réel (porté de GEDv1 `TsaService`, jamais câblé là-bas). Il construit une TimeStampReq
 * DER, la POSTe à la TSA par un transport injectable (curl par défaut) et vérifie la réponse :
 * SEQUENCE DER, statut « granted », empreinte couverte. La signature PKI de la TSA relève d'`openssl ts`.
 *
 * `TSA_URL` absente = refus NOMMÉ : on ne scelle jamais en silence sans horodatage qualifié.
 */
final class TsaReelle implements Horodateur
{
    /** @var callable(string, string): string */
    private readonly mixed $transport;

    public function __construct(private readonly string $url, ?callable $transport = null)
    {
        $this->transport = $transport ?? self::transportCurl(...);
    }

    public static function requete(string $empreinteBinaire): string
    {
        if (strlen($empreinteBinaire) !== 32) {
            throw ErreurScellement::horodatage('l’empreinte à horodater doit faire 32 octets (sha256)');
        }
        // AlgorithmIdentifier ::= SEQUENCE { OID sha256 2.16.840.1.101.3.4.2.1, NULL }
        $oid = Der::tlv(0x06, "\x60\x86\x48\x01\x65\x03\x04\x02\x01");
        $algorithme = Der::tlv(0x30, $oid . "\x05\x00");
        $messageImprint = Der::tlv(0x30, $algorithme . Der::tlv(0x04, $empreinteBinaire));

        // TimeStampReq ::= SEQUENCE { version v1(1), messageImprint, certReq TRUE }
        return Der::tlv(0x30, "\x02\x01\x01" . $messageImprint . "\x01\x01\xff");
    }

    public function horodater(string $empreinteBinaire): string
    {
        if (trim($this->url) === '') {
            throw ErreurScellement::horodatage('TSA_URL non configurée : aucun horodatage qualifié ne peut être posé');
        }
        $reponse = ($this->transport)($this->url, self::requete($empreinteBinaire));
        if (!Der::estSequence($reponse)) {
            throw ErreurScellement::horodatage('réponse TSA illisible (ce n’est pas une TimeStampResp DER)');
        }
        if (!Der::statutAccorde($reponse)) {
            throw ErreurScellement::horodatage('la TSA a refusé l’horodatage (statut différent de granted)');
        }
        if (!Der::couvre($reponse, $empreinteBinaire)) {
            throw ErreurScellement::horodatage('le jeton TSA ne couvre pas l’empreinte soumise');
        }

        return $reponse;
    }

    public function etiquette(): string
    {
        return $this->url;
    }

    /** Transport par défaut : POST curl, `Content-Type: application/timestamp-query`. */
    private static function transportCurl(string $url, string $requete): string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw ErreurScellement::horodatage("curl indisponible pour $url");
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requete,
            CURLOPT_HTTPHEADER => ['Content-Type: application/timestamp-query'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $reponse = curl_exec($curl);
        $erreur = curl_error($curl);
        curl_close($curl);
        if ($reponse === false) {
            throw ErreurScellement::horodatage("TSA injoignable ($url) : $erreur");
        }

        return (string) $reponse;
    }
}
