<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

use Ged\Config;

/**
 * Coffre des secrets du courrier : le mot de passe d'un compte IMAP doit être REVERSIBLE (le client doit le
 * rendre au serveur) mais jamais en clair au repos. Chiffrement authentifié AES-256-GCM via OpenSSL (présent
 * sur tout hébergement mutualisé) ; la clé de 32 octets vient de `GED_SECRET_KEY` (base64) ou, à défaut, d'une
 * clé locale créée une fois sous `var/courrier/.secret.key` (droits restreints, jamais commitée : `var/` est
 * ignoré). Le clair ne sort jamais d'ici : ni journal, ni réponse d'API, ni fichier de compte.
 */
final class Coffre
{
    public const VERSION = 'v1';
    private const ALGORITHME = 'aes-256-gcm';
    private const TAILLE_NONCE = 12;
    private const TAILLE_TAG = 16;

    private function __construct(private readonly string $cle)
    {
        if (strlen($this->cle) !== 32) {
            throw new ErreurCourrier('coffre : la clé doit faire 32 octets');
        }
    }

    /** Construit le coffre depuis la configuration (clé d'environnement, sinon clé locale persistée). */
    public static function depuisConfig(Config $config, ?string $dossierDonnees = null): self
    {
        $fournie = $config->get('GED_SECRET_KEY');
        if ($fournie !== '') {
            $binaire = base64_decode($fournie, true);
            if ($binaire === false || strlen($binaire) !== 32) {
                throw new ErreurCourrier('coffre : GED_SECRET_KEY doit être 32 octets en base64');
            }

            return new self($binaire);
        }

        $dossier = $dossierDonnees ?? $config->chemin('COURRIER_DONNEES', 'var/courrier');
        $fichier = $dossier . '/.secret.key';
        if (!is_file($fichier)) {
            if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
                throw new ErreurCourrier("coffre : dossier de clé impossible ($dossier)");
            }
            $cle = random_bytes(32);
            if (file_put_contents($fichier, base64_encode($cle)) === false) {
                throw new ErreurCourrier("coffre : écriture de la clé impossible ($fichier)");
            }
            @chmod($fichier, 0600);
        }
        $binaire = base64_decode(trim((string) file_get_contents($fichier)), true);
        if ($binaire === false || strlen($binaire) !== 32) {
            throw new ErreurCourrier('coffre : clé locale illisible ou de mauvaise taille');
        }

        return new self($binaire);
    }

    /** Coffre de test à clé connue : jamais la clé de production. */
    public static function depuisCleBinaire(string $cle): self
    {
        return new self($cle);
    }

    public function chiffrer(string $clair): string
    {
        $nonce = random_bytes(self::TAILLE_NONCE);
        $tag = '';
        $scelle = openssl_encrypt($clair, self::ALGORITHME, $this->cle, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAILLE_TAG);
        if ($scelle === false) {
            throw new ErreurCourrier('coffre : chiffrement impossible');
        }

        return self::VERSION . ':' . base64_encode($nonce . $tag . $scelle);
    }

    public function dechiffrer(string $scelle): string
    {
        if (!str_starts_with($scelle, self::VERSION . ':')) {
            throw new ErreurCourrier('coffre : format de secret inconnu (chiffrement obsolète ou altéré)');
        }
        $brut = base64_decode(substr($scelle, strlen(self::VERSION) + 1), true);
        if ($brut === false || strlen($brut) <= self::TAILLE_NONCE + self::TAILLE_TAG) {
            throw new ErreurCourrier('coffre : secret illisible');
        }
        $nonce = substr($brut, 0, self::TAILLE_NONCE);
        $tag = substr($brut, self::TAILLE_NONCE, self::TAILLE_TAG);
        $chiffre = substr($brut, self::TAILLE_NONCE + self::TAILLE_TAG);
        $clair = openssl_decrypt($chiffre, self::ALGORITHME, $this->cle, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($clair === false) {
            throw new ErreurCourrier('coffre : déchiffrement refusé (clé différente ou secret altéré)');
        }

        return $clair;
    }
}
