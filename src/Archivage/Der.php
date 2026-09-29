<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * Encodage DER minimal, partagé par la TSA réelle et la TSA simulée (RFC 3161).
 * Volontairement réduit au strict nécessaire : un TLV (tag, longueur, valeur), forme courte pour nos
 * requêtes (< 127 octets) et forme longue défensive. Aucune dépendance ASN.1 externe.
 */
final class Der
{
    /** Encode un TLV DER. */
    public static function tlv(int $tag, string $valeur): string
    {
        $longueur = strlen($valeur);
        if ($longueur <= 0x7F) {
            return chr($tag) . chr($longueur) . $valeur;
        }

        $octets = '';
        $reste = $longueur;
        while ($reste > 0) {
            $octets = chr($reste & 0xFF) . $octets;
            $reste >>= 8;
        }

        return chr($tag) . chr(0x80 | strlen($octets)) . $octets . $valeur;
    }

    /** Une réponse DER bien formée commence par une SEQUENCE (tag 0x30). */
    public static function estSequence(string $der): bool
    {
        return strlen($der) >= 10 && ord($der[0]) === 0x30;
    }

    /** Le statut « granted » de PKIStatusInfo est l'INTEGER 0 encodé `02 01 00`, en tête de la réponse. */
    public static function statutAccorde(string $der): bool
    {
        return str_contains(substr($der, 0, 32), "\x02\x01\x00");
    }

    /** Le jeton couvre cette empreinte binaire s'il la contient (MessageImprint/TSTInfo). */
    public static function couvre(string $der, string $empreinteBinaire): bool
    {
        return $empreinteBinaire !== '' && str_contains($der, $empreinteBinaire);
    }
}
