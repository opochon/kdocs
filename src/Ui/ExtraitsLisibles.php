<?php

declare(strict_types=1);

namespace Ged\Ui;

/**
 * V2-10c / V2-10d — lisibilité d'un extrait OCR. Un encadré de recherche ne doit jamais afficher
 * un amas de symboles, une bouillie de consonnes ni un chapelet de fragments : mieux vaut pas
 * d'extrait qu'un extrait illisible (le titre, le type, l'émetteur et la date suffisent).
 *
 * L'heuristique est volontairement sans dictionnaire, mais plus stricte que la seule présence de
 * voyelles (un bruit OCR scanne des mots courts qui en portent) :
 *
 *  1. assez de lettres dans le texte (part minimale de lettres) ;
 *  2. une majorité de mots portant une voyelle ;
 *  3. une part notable de « mots » ne doit pas être des fragments de ≤ 3 caractères hors liste de
 *     petits mots courants français, allemands et italiens (et abréviations administratives) ;
 *  4. aucun signe parasite (`{`, `|`, `~`, `#`, `@`, `^`, `\`, `<`, `>`, `_`…) ;
 *  5. au moins un mot de 5 lettres (un vrai extrait en porte un).
 *
 * Quand un extrait mélange bruit et texte, il est ROGNÉ au premier segment propre plutôt que montré
 * entier ou masqué. À défaut d'extrait propre, rien n'est rendu.
 *
 * Elle vit dans le secteur Interface : c'est une règle d'affichage, jamais une donnée métier.
 */
final class ExtraitsLisibles
{
    /**
     * Part minimale de lettres dans le texte (hors espaces). Basse exprès : un montant ou une
     * adresse porte chiffres, ponctuation et devises ; la soupe de symboles reste bien en dessous.
     */
    private const RATIO_LETTRES = 0.4;

    /** Part minimale de mots portant une voyelle. */
    private const RATIO_MOTS = 0.5;

    /** Nombre minimal de mots pour juger (en dessous, on ne montre pas). */
    private const MOTS_MINIMUM = 3;

    /** Longueur sous laquelle un mot est un « fragment » s'il n'est pas un petit mot connu. */
    private const LONGUEUR_FRAGMENT = 3;

    /** Un mot de cette longueur est « long » : un vrai extrait en porte au moins un. */
    private const LONGUEUR_MOT = 5;

    /** Part maximale de fragments tolérée dans un texte propre. Au-delà, on tente de rogner. */
    private const SEUIL_FRAGMENTS = 0.4;

    /** Minima d'un segment propre : assez de mots, assez de lettres, au moins un mot long. */
    private const SEGMENT_MIN_MOTS = 3;
    private const SEGMENT_MIN_LETTRES = 12;

    /**
     * Signes qui ne figurent pas dans un texte français, allemand ou italien lisible : leur seule
     * présence signe un artefact OCR (tampon, logo, trame de scan).
     */
    private const PARASITES = ['{', '}', '[', ']', '|', '~', '#', '@', '^', '\\', '<', '>', '`', '_', '¤', '¬', '¦'];

    /**
     * Petits mots courants (français, allemand, italien) de ≤ 3 caractères, plus les abréviations
     * administratives les plus fréquentes (devises, taxes, formes juridiques, adresses). Les formes
     * sont comparées sans casse ni accents.
     *
     * @var list<string>
     */
    private const PETITS_MOTS = [
        // — français (fonction, élisions, formes verbales courtes)
        'a', 'à', 'y', 'd', 'l', 'n', 'j', 'm', 't', 's', 'c', 'qu', 'ne', 'pas', 'que', 'qui',
        'ce', 'cet', 'ces', 'se', 'sa', 'son', 'ses', 'mon', 'ma', 'mes', 'ton', 'ta', 'tes',
        'nos', 'vos', 'un', 'une', 'des', 'du', 'de', 'le', 'la', 'les', 'et', 'ou', 'où', 'au',
        'aux', 'en', 'si', 'ni', 'or', 'car', 'par', 'sur', 'est', 'ont', 'été', 'fut', 'va',
        'vit', 'fit', 'moi', 'toi', 'soi', 'lui', 'eux', 'oui', 'non', 'ici', 'ça', 'ci', 'là',
        'dit', 'as', 'es', 'ai', 'eu', 'an', 'ans', 'ils', 'dix', 'six', 'peu',
        // — allemand
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'und', 'ist', 'war', 'was', 'wer', 'wie',
        'wo', 'ich', 'du', 'er', 'sie', 'es', 'wir', 'ihr', 'man', 'mit', 'von', 'zu', 'zum',
        'zur', 'im', 'in', 'am', 'an', 'auf', 'aus', 'bei', 'für', 'hat', 'nur', 'als', 'so',
        'da', 'ob', 'ja', 'nun', 'mir', 'dir', 'uns', 'vom', 'vor', 'bis', 'um', 'nie', 'mal',
        'weg', 'gut', 'neu', 'alt', 'rot', 'tag',
        // — italien
        'il', 'lo', 'la', 'le', 'i', 'gli', 'un', 'una', 'di', 'del', 'dei', 'da', 'dal', 'in',
        'nel', 'su', 'sul', 'con', 'per', 'tra', 'fra', 'e', 'ed', 'o', 'od', 'ma', 'se', 'che',
        'chi', 'cui', 'non', 'più', 'già', 'sia', 'suo', 'mia', 'mio', 'tua', 'tuo', 'nei', 'hai',
        'ha', 'ho', 'al', 'ai', 'era', 'fu', 'sto', 'sta', 'qua', 'qui', 'ne', 'ci', 'vi', 'si',
        'mi', 'ti', 'te', 'io', 'tu', 'lei', 'noi', 'voi', 'né', 'col', 'coi',
        // — abréviations administratives courantes
        'chf', 'eur', 'usd', 'ttc', 'tva', 'ht', 'sa', 'srl', 'ag', 'ch', 'av', 'tel', 'fax',
        'mme', 'mr', 'dr', 'pr', 'no', 'num', 'ref', 'obj', 'dos', 'bic', 'ccp',
    ];

    /** @var array<string,true>|null */
    private static ?array $petitsMots = null;

    /**
     * Texte propre à afficher pour un extrait brut, ou null si rien de lisible n'en sort.
     */
    public static function nettoyer(string $texte): ?string
    {
        $extrait = self::extraitPropre($texte);

        return $extrait === null ? null : $extrait['texte'];
    }

    /** Vrai si l'extrait porte au moins un passage propre à montrer. */
    public static function lisible(string $texte): bool
    {
        return self::extraitPropre($texte) !== null;
    }

    /**
     * Le premier segment propre de l'extrait, avec ses bornes en octets : le texte entier s'il est
     * propre, la fenêtre rognée sinon, null si aucun segment n'est propre.
     *
     * @return array{texte:string, debut:int, fin:int}|null
     */
    public static function extraitPropre(string $texte): ?array
    {
        if (trim($texte) === '') {
            return null;
        }

        $jetons = self::jetons($texte);
        $total = count($jetons);
        $i = 0;
        while ($i < $total) {
            if (!$jetons[$i]['acceptable']) {
                $i++;
                continue;
            }
            $j = $i;
            $lettres = 0;
            $maxLettres = 0;
            while ($j < $total && $jetons[$j]['acceptable']) {
                $lettres += $jetons[$j]['longueur'];
                $maxLettres = max($maxLettres, $jetons[$j]['longueur']);
                $j++;
            }
            $mots = $j - $i;
            if ($mots >= self::SEGMENT_MIN_MOTS && $lettres >= self::SEGMENT_MIN_LETTRES && $maxLettres >= self::LONGUEUR_MOT) {
                $debut = $jetons[$i]['debut'];
                // Fin du segment : jusqu'au mot suivant, en gardant la ponctuation et les chiffres
                // collés (« 2026. »), mais jamais en franchissant un signe parasite.
                $limite = $j < $total ? $jetons[$j]['debut'] : strlen($texte);
                $fin = $jetons[$j - 1]['fin'];
                while ($fin < $limite && !self::parasiteEn($texte, $fin)) {
                    $fin++;
                }
                $candidat = rtrim(substr($texte, $debut, $fin - $debut));
                $fin = $debut + strlen($candidat);
                if (!self::contientParasite($candidat) && self::propre($candidat)) {
                    $bruitAvant = 0;
                    for ($k = 0; $k < $i; $k++) {
                        if (!$jetons[$k]['acceptable']) {
                            $bruitAvant++;
                        }
                    }
                    $parasiteAvant = self::contientParasite(substr($texte, 0, $debut));
                    $propreEntier = self::propre($texte);
                    // Début propre : on ne rogne que si l'extrait entier n'est pas propre (queue
                    // bruitée). Sinon, on rogne dès qu'un préfixe porte deux fragments ou un signe
                    // parasite, même si l'adresse lisible dilue les ratios — c'est la forme du juge.
                    $rogner = $debut === 0
                        ? !$propreEntier
                        : ($bruitAvant >= 2 || $parasiteAvant || !$propreEntier);
                    if ($rogner) {
                        return ['texte' => $candidat, 'debut' => $debut, 'fin' => $fin];
                    }

                    return ['texte' => $texte, 'debut' => 0, 'fin' => strlen($texte)];
                }
            }
            $i = $j;
        }

        // Aucun segment assez long : un texte court mais propre reste montrable.
        if (self::propre($texte)) {
            return ['texte' => $texte, 'debut' => 0, 'fin' => strlen($texte)];
        }

        return null;
    }

    /**
     * Ne retient que les extraits qui portent un passage propre, dans l'ordre, et borne le nombre
     * affiché. Un extrait mixte est rogné au premier segment propre ; les marques de surlignage des
     * segments conservés sont préservées.
     *
     * @param list<list<array{t:string, hit:bool}>> $extraits
     * @return list<list<array{t:string, hit:bool}>>
     */
    public static function retenir(array $extraits, int $max = 3): array
    {
        $gardes = [];
        foreach ($extraits as $extrait) {
            if (count($gardes) >= $max) {
                break;
            }
            $segments = [];
            foreach ($extrait as $segment) {
                if (is_array($segment)) {
                    $segments[] = [
                        't' => (string) ($segment['t'] ?? ''),
                        'hit' => (bool) ($segment['hit'] ?? false),
                    ];
                }
            }
            $texte = implode('', array_map(static fn (array $s): string => $s['t'], $segments));
            $propre = self::extraitPropre($texte);
            if ($propre === null) {
                continue;
            }
            if ($propre['debut'] === 0 && $propre['fin'] === strlen($texte)) {
                $gardes[] = $segments;
                continue;
            }
            $gardes[] = self::rogner($segments, $propre['debut'], $propre['fin']);
        }

        return $gardes;
    }

    /**
     * Coupe la liste de segments aux bornes [debut, fin) du texte concaténé.
     *
     * @param list<array{t:string, hit:bool}> $segments
     * @return list<array{t:string, hit:bool}>
     */
    private static function rogner(array $segments, int $debut, int $fin): array
    {
        $gardes = [];
        $offset = 0;
        foreach ($segments as $segment) {
            $longueur = strlen($segment['t']);
            $segmentDebut = $offset;
            $segmentFin = $offset + $longueur;
            $offset = $segmentFin;
            $a = max($debut, $segmentDebut);
            $b = min($fin, $segmentFin);
            if ($b <= $a) {
                continue;
            }
            $gardes[] = [
                't' => substr($segment['t'], $a - $segmentDebut, $b - $a),
                'hit' => $segment['hit'],
            ];
        }

        return $gardes;
    }

    /** Vrai si le texte, pris entier, est lisible d'un bout à l'autre. */
    private static function propre(string $texte): bool
    {
        $compact = (string) preg_replace('/\s+/u', '', $texte);
        $total = mb_strlen($compact);
        if ($total < 8) {
            return false;
        }
        $lettres = preg_match_all('/\p{L}/u', $compact);
        if ($lettres === false || $lettres / $total < self::RATIO_LETTRES) {
            return false;
        }
        if (self::contientParasite($texte)) {
            return false;
        }

        $jetons = self::jetons($texte);
        $mots = count($jetons);
        if ($mots < self::MOTS_MINIMUM) {
            return false;
        }
        $fragments = 0;
        $voyelles = 0;
        $longs = 0;
        foreach ($jetons as $jeton) {
            if (!$jeton['acceptable']) {
                $fragments++;
            }
            if ($jeton['longueur'] >= self::LONGUEUR_MOT) {
                $longs++;
            }
            if (preg_match('/[aeiouyàâäéèêëîïôöùûüœæ]/iu', $jeton['lettres']) === 1) {
                $voyelles++;
            }
        }
        if ($fragments / $mots > self::SEUIL_FRAGMENTS) {
            return false;
        }
        if ($voyelles / $mots < self::RATIO_MOTS) {
            return false;
        }

        return $longs >= 1;
    }

    /**
     * Découpe le texte en mots (lettres, tirets internes) avec leurs bornes en octets.
     *
     * @return list<array{t:string, lettres:string, debut:int, fin:int, longueur:int, acceptable:bool}>
     */
    private static function jetons(string $texte): array
    {
        $trouves = preg_match_all('/\p{L}[\p{L}\-]*/u', $texte, $mots, PREG_OFFSET_CAPTURE);
        if ($trouves === false || $trouves === 0) {
            return [];
        }
        $jetons = [];
        foreach ($mots[0] as [$mot, $debut]) {
            $lettres = (string) preg_replace('/[^\p{L}]/u', '', $mot);
            $longueur = mb_strlen($lettres);
            $jetons[] = [
                't' => $mot,
                'lettres' => $lettres,
                'debut' => $debut,
                'fin' => $debut + strlen($mot),
                'longueur' => $longueur,
                'acceptable' => $longueur > self::LONGUEUR_FRAGMENT || self::petitMot($lettres),
            ];
        }

        return $jetons;
    }

    /** Un petit mot connu (sans casse ni accents) n'est pas un fragment. */
    private static function petitMot(string $mot): bool
    {
        if (self::$petitsMots === null) {
            // La liste porte les accents ; la comparaison les ignore des deux côtés.
            self::$petitsMots = array_fill_keys(array_map(self::sansAccents(...), self::PETITS_MOTS), true);
        }

        return isset(self::$petitsMots[self::sansAccents(mb_strtolower($mot))]);
    }

    /** Retire les diacritiques pour comparer les mots d'une langue à l'autre. */
    private static function sansAccents(string $texte): string
    {
        return strtr($texte, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
        ]);
    }

    /** Vrai si le texte porte un signe qui n'appartient pas à un document lisible. */
    private static function contientParasite(string $texte): bool
    {
        foreach (self::PARASITES as $parasite) {
            if (str_contains($texte, $parasite)) {
                return true;
            }
        }

        return false;
    }

    /** Vrai si un signe parasite commence exactement à l'octet donné (arrêt du rognage). */
    private static function parasiteEn(string $texte, int $offset): bool
    {
        foreach (self::PARASITES as $parasite) {
            if (substr($texte, $offset, strlen($parasite)) === $parasite) {
                return true;
            }
        }

        return false;
    }
}
