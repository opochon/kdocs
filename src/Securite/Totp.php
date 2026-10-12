<?php

declare(strict_types=1);

namespace Ged\Securite;

use PDO;
use RuntimeException;

/**
 * Double authentification TOTP (RFC 6238) à la manière GEDv2 — équivalent fonctionnel du modèle
 * `allauth.mfa.Authenticator` de Paperless, sans dépendance à django-otp.
 *
 * Le secret est aléatoire (20 octets, rendu en base32 RFC 4648 pour l'application d'authentification),
 * stocké EN BASE dans `securite_totp` ; il n'est rendu en clair qu'à l'enrôlement, jamais relu ensuite.
 * Les codes de secours sont rendus UNE SEULE FOIS à l'activation, stockés hachés (`password_hash`).
 * Aucun secret n'est journalisé.
 */
final class Totp
{
    /** Période du pas de temps (secondes), conforme à la recommandation RFC 6238. */
    public const PERIODE = 30;

    /** Nombre de chiffres du code. */
    public const CHIFFRES = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assurer(): void
    {
        Schema::installer($this->pdo);
    }

    /** Secret aléatoire codé en base32 (sans remplissage) : 20 octets = 160 bits. */
    public function genererSecret(): string
    {
        return self::base32(random_bytes(20));
    }

    /** URI `otpauth://` à présenter à l'application d'authentification (jamais le secret seul). */
    public function uri(string $secret, string $identifiant, string $emetteur = 'GEDv2'): string
    {
        $etiquette = rawurlencode($emetteur . ':' . $identifiant);

        return 'otpauth://totp/' . $etiquette . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($emetteur) . '&algorithm=SHA1&digits=' . self::CHIFFRES
            . '&period=' . self::PERIODE;
    }

    /**
     * Image d'enrôlement : GEDv2 n'embarque pas de moteur QR côté serveur (hébergement mutualisé, aucune
     * dépendance d'image). L'URI `otpauth://` est rendue lisible pour saisie manuelle ou génération côté
     * client ; le champ `qr_svg` reste un SVG valide (jamais une image externe).
     */
    public function svg(string $uri): string
    {
        $lignes = str_split($uri, 64);
        $texte = '';
        $y = 24;
        foreach ($lignes as $ligne) {
            $texte .= '<text x="12" y="' . $y . '" font-family="monospace" font-size="11">'
                . htmlspecialchars($ligne, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</text>';
            $y += 16;
        }
        $hauteur = $y + 8;

        return '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="' . $hauteur . '" role="img" aria-label="otpauth">'
            . '<rect width="100%" height="100%" fill="#ffffff"/>' . $texte . '</svg>';
    }

    /** Code TOTP pour un secret (base32) et un compteur de pas de temps. */
    public function code(string $secret, int $compteur): string
    {
        $cle = self::base32Decode($secret);
        if ($cle === '') {
            throw new RuntimeException('TOTP : secret vide ou illisible');
        }
        $donnees = pack('N*', 0) . pack('N*', $compteur);
        $empreinte = hash_hmac('sha1', $donnees, $cle, true);
        $decalage = ord($empreinte[strlen($empreinte) - 1]) & 0x0F;
        $valeur = ((ord($empreinte[$decalage]) & 0x7F) << 24)
            | ((ord($empreinte[$decalage + 1]) & 0xFF) << 16)
            | ((ord($empreinte[$decalage + 2]) & 0xFF) << 8)
            | (ord($empreinte[$decalage + 3]) & 0xFF);

        return str_pad((string) ($valeur % (10 ** self::CHIFFRES)), self::CHIFFRES, '0', STR_PAD_LEFT);
    }

    /** Vérifie un code sur une petite fenêtre (horloges décalées), jamais au-delà d'un pas. */
    public function verifier(string $secret, string $codeSaisi, int $fenetre = 1, ?int $maintenant = null): bool
    {
        $codeSaisi = (string) preg_replace('/\D/', '', $codeSaisi);
        if (strlen($codeSaisi) !== self::CHIFFRES) {
            return false;
        }
        $compteur = intdiv($maintenant ?? time(), self::PERIODE);
        for ($decalage = -$fenetre; $decalage <= $fenetre; ++$decalage) {
            if (hash_equals($this->code($secret, $compteur + $decalage), $codeSaisi)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Active le TOTP d'un compte : le secret est stocké, les codes de secours hachés ; les codes en clair
     * sont rendus une seule fois à l'appelant, jamais relus.
     *
     * @return list<string> codes de secours en clair
     */
    public function activer(int $compteId, string $secret): array
    {
        $this->assurer();
        $codes = self::codesSecours();
        $hachages = array_map(static fn (string $code): string => password_hash($code, PASSWORD_DEFAULT), $codes);
        $this->pdo->prepare('INSERT INTO securite_totp (compte_id, secret, codes_secours, actif, active_le) VALUES (?, ?, ?, 1, NOW())'
            . ' ON DUPLICATE KEY UPDATE secret = VALUES(secret), codes_secours = VALUES(codes_secours), actif = 1, active_le = NOW()')
            ->execute([$compteId, $secret, (string) json_encode($hachages)]);

        return $codes;
    }

    /** @return array{secret:string, actif:bool}|null */
    public function etat(int $compteId): ?array
    {
        try {
            $requete = $this->pdo->prepare('SELECT secret, actif FROM securite_totp WHERE compte_id = ?');
            $requete->execute([$compteId]);
            $ligne = $requete->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if ($ligne === false) {
            return null;
        }

        return ['secret' => (string) $ligne['secret'], 'actif' => (int) $ligne['actif'] === 1];
    }

    /** Désactive le TOTP d'un compte ; rend faux s'il n'était pas activé (404 nommé côté API). */
    public function desactiver(int $compteId): bool
    {
        try {
            $requete = $this->pdo->prepare('SELECT 1 FROM securite_totp WHERE compte_id = ?');
            $requete->execute([$compteId]);
            if ($requete->fetchColumn() === false) {
                return false;
            }
            $this->pdo->prepare('DELETE FROM securite_totp WHERE compte_id = ?')->execute([$compteId]);
        } catch (\PDOException) {
            return false;
        }

        return true;
    }

    /** @return list<string> */
    public static function codesSecours(int $nombre = 6): array
    {
        $codes = [];
        for ($i = 0; $i < $nombre; ++$i) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }

        return $codes;
    }

    private static function base32(string $donnees): string
    {
        $bits = '';
        foreach (str_split($donnees) as $octet) {
            $bits .= str_pad(decbin(ord($octet)), 8, '0', STR_PAD_LEFT);
        }
        $sortie = '';
        foreach (str_split($bits, 5) as $groupe) {
            $sortie .= self::ALPHABET[bindec(str_pad($groupe, 5, '0', STR_PAD_RIGHT))];
        }

        return $sortie;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper((string) preg_replace('/[^A-Z2-7]/i', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $caractere) {
            $position = strpos(self::ALPHABET, $caractere);
            if ($position === false) {
                continue;
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $sortie = '';
        foreach (str_split($bits, 8) as $octet) {
            if (strlen($octet) < 8) {
                break;
            }
            $sortie .= chr(bindec($octet));
        }

        return $sortie;
    }
}
