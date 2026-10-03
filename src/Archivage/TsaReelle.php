<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * Client RFC 3161 réel (porté de GEDv1 `TsaService`, jamais câblé là-bas). Il construit une TimeStampReq
 * DER, la POSTe à la TSA par un transport injectable (curl par défaut) et vérifie la réponse :
 * SEQUENCE DER, statut « granted », empreinte couverte, nonce et signature CMS. Chaque réponse est vérifiée
 * par `openssl ts -verify` contre les racines versionnées avant d'être acceptée.
 *
 * `TSA_URL` absente = refus NOMMÉ : on ne scelle jamais en silence sans TSA publique configurée.
 */
final class TsaReelle implements Horodateur
{
    /** @var callable(string, string): string */
    private readonly mixed $transport;
    /** @var callable(string, string): string */
    private readonly mixed $verifier;
    private string $derniereRequete = '';
    private string $derniereChaine = '';

    public function __construct(private readonly string $url, ?callable $transport = null, ?callable $verifier = null)
    {
        $this->transport = $transport ?? self::transportCurl(...);
        $this->verifier = $verifier ?? OpenSslTsa::verifier(...);
    }

    /** @return list<string> */
    public static function urls(string $liste): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $liste)), static fn (string $url): bool => $url !== ''));
    }

    public static function requete(string $empreinteBinaire, ?string $nonce = null): string
    {
        if (strlen($empreinteBinaire) !== 32) {
            throw ErreurScellement::horodatage('l’empreinte à horodater doit faire 32 octets (sha256)');
        }
        // AlgorithmIdentifier ::= SEQUENCE { OID sha256 2.16.840.1.101.3.4.2.1, NULL }
        $oid = Der::tlv(0x06, "\x60\x86\x48\x01\x65\x03\x04\x02\x01");
        $algorithme = Der::tlv(0x30, $oid . "\x05\x00");
        $messageImprint = Der::tlv(0x30, $algorithme . Der::tlv(0x04, $empreinteBinaire));

        // RFC 3161 §2.4.1 : un nonce 64 bits aléatoire doit être renvoyé identiquement par la TSA.
        $nonce ??= random_bytes(8);
        if (strlen($nonce) !== 8) {
            throw ErreurScellement::horodatage('nonce TSA invalide : 64 bits attendus');
        }
        $nonceEntier = ord($nonce[0]) >= 0x80 ? "\x00" . $nonce : $nonce;

        // TimeStampReq ::= SEQUENCE { version, messageImprint, nonce, certReq TRUE }
        return Der::tlv(0x30, "\x02\x01\x01" . $messageImprint . Der::tlv(0x02, $nonceEntier) . "\x01\x01\xff");
    }

    public function horodater(string $empreinteBinaire): string
    {
        $urls = self::urls($this->url);
        if ($urls === []) {
            throw ErreurScellement::horodatage('TSA_URL non configurée : aucune TSA publique non qualifiée ne peut être jointe');
        }
        $causes = [];
        foreach ($urls as $url) {
            $requete = self::requete($empreinteBinaire);
            try {
                $reponse = ($this->transport)($url, $requete);
                if (!Der::estSequence($reponse) || !Der::statutAccorde($reponse) || !Der::couvre($reponse, $empreinteBinaire)) {
                    throw ErreurScellement::horodatage('réponse refusée : statut, structure ou empreinte invalide');
                }
                $chaine = ($this->verifier)($requete, $reponse);
                $this->derniereRequete = $requete;
                $this->derniereChaine = $chaine;
                $this->urlUtilisee = $url;

                return $reponse;
            } catch (ErreurScellement $e) {
                $causes[] = $url . ' : ' . $e->getMessage();
            } catch (\RuntimeException $e) {
                $causes[] = $url . ' : ' . $e->getMessage();
            }
        }
        throw ErreurScellement::horodatage('toutes les TSA ont refusé ou sont injoignables — ' . implode(' ; ', $causes));
    }

    public function etiquette(): string
    {
        return $this->urlUtilisee ?? $this->url;
    }

    private ?string $urlUtilisee = null;

    public function requeteEnvoyee(): string { return $this->derniereRequete; }
    public function chaineCertificats(): string { return $this->derniereChaine; }

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
