<?php

declare(strict_types=1);

namespace Ged\Classement;

/**
 * Règles de lecture du texte d'une pièce, sans IA et sans base.
 *
 * - L'émetteur est le premier titre lisible du document (gras markdown, titre, ou première ligne) ;
 *   le propriétaire de la GED n'est jamais l'émetteur : c'est le destinataire.
 * - Un type n'est reconnu que sur un indice fort dans le texte (typeCorrobore de GEDv1) ; « Autre »
 *   n'est jamais proposé, et aucun indice ne donne jamais « Autre ».
 * - Le pliage des accents est fait par une table maison : l'iconv local translittère « É » en « E' ».
 */
final class Regles
{
    /** Indices forts, du plus spécifique au plus générique. Un contrat de travail est RH, pas Contrat. */
    private const INDICES = [
        'Note de crédit' => ['note de credit', 'gutschrift', 'credit note'],
        'Document RH' => ['attestation de travail', 'certificat de travail', 'fiche de paie', 'bulletin de paie',
            'contrat de travail', 'decompte de salaire', 'employeur', 'lohn'],
        'Facture' => ['facture', 'invoice', 'rechnung', 'fattura', 'avis de paiement', 'net a payer'],
        'Contrat' => ['contrat', 'convention', 'avenant', 'soussign', 'les parties', 'vertrag', 'police'],
        'Reçu' => ['recu', 'quittance', 'ticket', 'quittung', 'encaisse', 'paye le'],
    ];

    private const INDICE_COURRIER = 'concerne';
    private const CONF_FORTE = 0.92;
    private const CONF_COURRIER = 0.82;

    /** Types connus tant que la nomenclature (V2-04) n'existe pas. */
    private const TYPES_CONNUS = ['Facture', 'Note de crédit', 'Reçu', 'Contrat', 'Document RH', 'Courrier'];

    /** Mots trop courts ou trop génériques pour identifier un émetteur. */
    private const MOTS_VIDES = ['sa', 'sarl', 'sagl', 'ag', 'gmbh', 'snc', 'holding', 'les', 'des', 'du', 'de', 'la', 'le', 'the'];

    private const PLIAGE = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ß' => 'ss', 'š' => 's', 'ś' => 's', 'ş' => 's', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
        'đ' => 'd', 'ð' => 'd', 'þ' => 'th', 'ł' => 'l',
    ];

    /** @return list<string> */
    public static function typesConnus(): array
    {
        return self::TYPES_CONNUS;
    }

    /** Minuscules, sans accents, espaces simples — pour comparer noms et indices. */
    public static function normaliser(string $s): string
    {
        $t = mb_strtolower($s, 'UTF-8');
        $t = strtr($t, self::PLIAGE);
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? '';

        return trim((string) preg_replace('/\s+/', ' ', $t));
    }

    public static function memeType(?string $a, ?string $b): bool
    {
        $na = $a !== null ? self::normaliser($a) : '';

        return $na !== '' && $na === ($b !== null ? self::normaliser($b) : '');
    }

    /** « Autre », « divers », « inconnu » ne sont pas des classements : jamais validés seuls. */
    public static function estAutre(?string $type): bool
    {
        return $type !== null && in_array(self::normaliser($type), ['autre', 'autres', 'divers', 'inconnu', 'aucun'], true);
    }

    /**
     * Le type que le texte désigne, avec l'indice qui le prouve. Null si le texte ne dit rien.
     *
     * @return array{type:string, preuve:string, confiance:float}|null
     */
    public static function typeEtPreuve(string $texte): ?array
    {
        $n = self::normaliser($texte);
        foreach (self::INDICES as $type => $indices) {
            foreach ($indices as $indice) {
                if (self::contientMot($n, $indice)) {
                    return ['type' => $type, 'preuve' => $indice, 'confiance' => self::CONF_FORTE];
                }
            }
        }
        if (self::contientMot($n, self::INDICE_COURRIER)) {
            return ['type' => 'Courrier', 'preuve' => self::INDICE_COURRIER, 'confiance' => self::CONF_COURRIER];
        }

        return null;
    }

    /** L'indice qui corrobore un type donné, ou null : c'est la preuve exigée avant de valider seul. */
    public static function indiceType(string $texte, ?string $type): ?string
    {
        if ($type === null) {
            return null;
        }
        $r = self::typeEtPreuve($texte);

        return $r !== null && self::memeType($r['type'], $type) ? $r['preuve'] : null;
    }

    /**
     * L'émetteur lu dans l'en-tête : premier titre (gras, titre markdown) puis première ligne pleine.
     * Les lignes d'adresse (chiffres) et le propriétaire de la GED sont écartés ; sinon null.
     */
    public static function lireEmetteur(string $texte, string $proprietaire = ''): ?string
    {
        $extrait = mb_substr($texte, 0, 2000);

        if (preg_match_all('/\*\*(.+?)\*\*/s', $extrait, $m)) {
            foreach ($m[1] as $brut) {
                $candidat = self::nettoyer($brut);
                if (self::candidatEmetteur($candidat, $proprietaire)) {
                    return $candidat;
                }
            }
        }
        if (preg_match_all('/^#{1,6}\s+(.+)$/m', $extrait, $m)) {
            foreach ($m[1] as $brut) {
                $candidat = self::nettoyer($brut);
                if (self::candidatEmetteur($candidat, $proprietaire)) {
                    return $candidat;
                }
            }
        }
        $lignes = preg_split('/\R/', $extrait) ?: [];
        foreach (array_slice($lignes, 0, 15) as $ligne) {
            $candidat = self::nettoyer($ligne);
            if (self::candidatEmetteur($candidat, $proprietaire)) {
                return $candidat;
            }
        }

        return null;
    }

    /** Le nom se lit-il vraiment dans le texte ? (le modèle recopie volontiers un nom de sa liste) */
    public static function nomSeLit(string $texte, string $nom): bool
    {
        $t = ' ' . self::normaliser($texte) . ' ';
        $mots = array_values(array_filter(explode(' ', self::normaliser($nom)),
            static fn (string $m): bool => strlen($m) >= 3 && !in_array($m, self::MOTS_VIDES, true)));
        if ($mots === [] || trim($t) === '') {
            return false;
        }
        if (str_contains($t, ' ' . implode(' ', array_slice($mots, 0, 2)) . ' ')) {
            return true;
        }
        $trouves = count(array_filter($mots, static fn (string $m): bool => str_contains($t, ' ' . $m . ' ')));

        return $trouves >= min(2, count($mots));
    }

    /** Un Document RH n'est valide que si le propriétaire de la GED (GED_ORGANISATION) figure dans la pièce. */
    public static function proprietaireDansTexte(string $texte, string $proprietaire): bool
    {
        return trim($proprietaire) !== '' && self::nomSeLit($texte, $proprietaire);
    }

    private static function contientMot(string $texteNormalise, string $indice): bool
    {
        return $texteNormalise !== '' && str_contains(' ' . $texteNormalise . ' ', ' ' . $indice . ' ');
    }

    private static function nettoyer(string $ligne): string
    {
        $ligne = preg_replace('/[\p{Z}]+/u', ' ', $ligne) ?? $ligne;
        $ligne = preg_replace('/[`*#_>|]+/', ' ', $ligne) ?? '';

        return trim((string) preg_replace('/\s+/', ' ', $ligne));
    }

    private static function candidatEmetteur(string $candidat, string $proprietaire): bool
    {
        if (mb_strlen($candidat) < 3 || mb_strlen($candidat) > 100) {
            return false;
        }
        if (preg_match('/\d/', $candidat) === 1 || str_contains($candidat, '@') || str_contains(mb_strtolower($candidat), 'http')) {
            return false;
        }
        if (count(preg_split('/\s+/', $candidat) ?: []) < 2) {
            return false;
        }
        $n = self::normaliser($candidat);
        if ($n === '') {
            return false;
        }
        foreach (['concerne', 'objet', 'page ', 'date ', 'recu', 'facture', 'note de credit', 'total', 'article'] as $motif) {
            if (str_starts_with($n, $motif)) {
                return false;
            }
        }
        $p = self::normaliser($proprietaire);
        if ($p !== '' && (str_contains($n, $p) || str_contains($p, $n))) {
            return false;
        }

        return true;
    }
}
