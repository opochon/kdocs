<?php

declare(strict_types=1);

namespace Ged\Classement;

/**
 * Règles de lecture du texte d'une pièce, sans IA et sans base.
 *
 * - L'émetteur est un nom institutionnel lisible dans la pièce (en-tête ou pied de page), jamais une
 *   ligne OCR isolée ; le propriétaire de la GED n'est jamais l'émetteur : c'est le destinataire.
 * - Un type n'est reconnu que sur un indice fort dans le texte (typeCorrobore de GEDv1) ; « Autre »
 *   n'est jamais proposé, et aucun indice ne donne jamais « Autre ».
 * - Le pliage des accents est fait par une table maison : l'iconv local translittère « É » en « E' ».
 */
final class Regles
{
    /** Indices forts, du plus spécifique au plus générique. Un contrat de travail est RH, pas Contrat. */
    private const INDICES = [
        'Document RH' => ['attestation de travail', 'certificat de travail', 'fiche de paie', 'bulletin de paie',
            'contrat de travail', 'decompte de salaire', 'employeur', 'lohn'],
        'Avis d’impôt' => ['avis d impot', 'bordereau d impot', 'taxation fiscale', 'steuerveranlagung', 'steuerrechnung', 'avviso fiscale'],
        'Police d’assurance' => ['police assurance', 'police d assurance', 'versicherungspolice', 'versicherungsschein', 'polizza assicurativa', 'polizza'],
        'Décision' => ['decision', 'decisione', 'decision administrative', 'decision judiciaire', 'decision sur', 'verfugung', 'bescheid', 'sentenza', 'provvedimento'],
        'Décompte' => ['decompte', 'decompte de prestations', 'abrechnung', 'abrechnung leistungen', 'conteggio', 'conteggio prestazioni'],
        'Relevé' => ['releve', 'releve de compte', 'releve bancaire', 'kontoauszug', 'auszug', 'estratto conto', 'estratto'],
        'Attestation' => ['attestation', 'attestation fiscale', 'attestation annuelle', 'bescheinigung', 'steuerbescheinigung', 'attestazione', 'certificazione'],
        'Note de crédit' => ['note de credit', 'gutschrift', 'credit note'],
        'Facture' => ['facture', 'invoice', 'rechnung', 'fattura', 'avis de paiement', 'net a payer'],
        'Contrat' => ['contrat', 'convention', 'avenant', 'soussign', 'les parties', 'vertrag', 'police'],
        'Reçu' => ['recu', 'quittance', 'ticket', 'quittung', 'encaisse', 'paye le'],
    ];

    private const INDICE_COURRIER = 'concerne';
    private const CONF_FORTE = 0.92;
    private const CONF_COURRIER = 0.82;

    /** Types connus tant que la nomenclature (V2-04) n'existe pas. */
    private const TYPES_CONNUS = ['Facture', 'Note de crédit', 'Reçu', 'Contrat', 'Courrier', 'Document RH',
        'Décompte', 'Relevé', 'Attestation', 'Police d’assurance', 'Avis d’impôt', 'Décision'];

    /** Mots trop courts ou trop génériques pour identifier un émetteur. */
    private const MOTS_VIDES = ['sa', 'sarl', 'sagl', 'ag', 'gmbh', 'snc', 'holding', 'les', 'des', 'du', 'de', 'la', 'le', 'the'];

    /** Des mots de service n'identifient jamais à eux seuls une organisation. */
    private const MOTS_EMETTEUR_GENERIQUES = ['banque', 'service', 'assurance', 'assurances', 'relation', 'client',
        'clients', 'contact', 'center', 'centre', 'customer', 'care'];

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
     * L'émetteur lu dans les zones textuelles de la pièce : titre puis lignes. Une ligne ne suffit pas
     * si elle n'a pas les marqueurs d'une organisation. Cela laisse remonter un logo/pied de page lu par
     * OCR, mais écarte « KKK KK », « Adresse principal: » et les phrases de corps.
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
        foreach ($lignes as $ligne) {
            $candidat = self::nettoyer($ligne);
            if (self::candidatEmetteur($candidat, $proprietaire)) {
                return $candidat;
            }
        }

        return null;
    }

    /** Deux noms qui désignent le même dossier métier (un type précis et son type parent). */
    public static function typesCompatibles(?string $a, ?string $b): bool
    {
        if (self::memeType($a, $b)) {
            return true;
        }
        $paire = [self::normaliser((string) $a), self::normaliser((string) $b)];
        sort($paire);

        return in_array($paire, [
            ['decompte', 'facture'],
            ['contrat', 'police d assurance'],
            ['attestation', 'document rh'],
            ['avis d impot', 'facture'],
        ], true);
    }

    /** Parmi deux membres d'une même famille, garder le sous-type utile à l'utilisateur. */
    public static function typeLePlusPrecis(?string $a, ?string $b): ?string
    {
        if (self::memeType($a, $b)) {
            return $a;
        }
        foreach ([['Décompte', 'Facture'], ['Police d’assurance', 'Contrat'], ['Attestation', 'Document RH'], ['Avis d’impôt', 'Facture']] as [$precis, $parent]) {
            if (self::typesCompatibles($a, $b) && (self::memeType($a, $precis) || self::memeType($b, $precis))
                && (self::memeType($a, $parent) || self::memeType($b, $parent))) {
                return $precis;
            }
        }

        return $a ?? $b;
    }

    /** Une proposition IA est crédible seulement si son nom est lisible et ressemble à une organisation. */
    public static function emetteurIaCredible(string $texte, ?string $nom, string $proprietaire = ''): ?string
    {
        if ($nom === null) {
            return null;
        }
        $candidat = self::nettoyer($nom);

        return self::candidatEmetteur($candidat, $proprietaire) && self::nomSeLit($texte, $candidat) ? $candidat : null;
    }

    /** Le nom se lit-il vraiment dans le texte ? (le modèle recopie volontiers un nom de sa liste) */
    public static function nomSeLit(string $texte, string $nom): bool
    {
        $mots = self::motsSignificatifs($nom);
        if ($mots === [] || trim($texte) === '') {
            return false;
        }

        $texteMots = self::motsSignificatifs($texte);

        return array_values(array_diff($mots, $texteMots)) === [];
    }

    /** Rapproche IA/texte sans être piégé par casse, accents, ponctuation ou forme juridique. */
    public static function emetteursCorrespondent(?string $a, ?string $b): bool
    {
        $motsA = self::motsSignificatifs((string) $a);
        $motsB = self::motsSignificatifs((string) $b);
        if ($motsA === [] || $motsB === []) {
            return false;
        }
        if (self::memeType($a, $b)) {
            return true;
        }
        $communs = array_values(array_intersect($motsA, $motsB));
        if ($communs === []) {
            return false;
        }

        // Une inclusion complète (p. ex. « Banque du Lac SA » / « Banque du Lac AG ») est une
        // concordance. Un seul mot générique a déjà été exclu par motsSignificatifs().
        return array_values(array_diff($motsA, $motsB)) === [] || array_values(array_diff($motsB, $motsA)) === [];
    }

    /** Un Document RH n'est valide que si le propriétaire de la GED (GED_ORGANISATION) figure dans la pièce. */
    public static function proprietaireDansTexte(string $texte, string $proprietaire): bool
    {
        return trim($proprietaire) !== '' && self::nomSeLit($texte, $proprietaire);
    }

    /**
     * La date d'émission : une date suisse située dans une ligne qui la qualifie (« <lieu>, le … »,
     * « Date d'émission : … », « Date : … »). Une date de naissance, une période de prestation ou un
     * millésime historique ne peuvent donc pas gagner parce qu'ils apparaissent plus haut dans l'OCR.
     */
    public static function dateEnTete(string $texte): ?string
    {
        $lignes = preg_split('/\R/', mb_substr($texte, 0, 2000)) ?: [];
        $dernierIndiceFacture = -99;
        $ligneDocument = 0;
        foreach ($lignes as $index => $ligne) {
            $normalise = self::normaliser($ligne);
            if (preg_match('/\b(naissance|ne le|nee le|periode|prestation|depuis|archive|ancien(?:ne)?)\b/', $normalise) === 1) {
                continue;
            }
            if (trim($normalise) !== '') {
                $ligneDocument++;
            }
            if (preg_match('/\b(facture|invoice|rechnung|fattura)\b|\bn[°o]\s*[a-z0-9]/iu', $ligne) === 1) {
                $dernierIndiceFacture = $index;
            }
            if (preg_match('/\b(echeance|échéance|due date|zahlbar|scadenza)\b/u', $ligne) === 1) {
                continue;
            }
            if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $ligne, $m) === 1) {
                $estDateDocument = preg_match('/\b(date|datum|data|rechnungsdatum)\b|,\s*le\s+\d{1,2}\.|\b(?:le|den|il)\s+\d{1,2}\./iu', $ligne) === 1
                    || $ligneDocument <= 6 || $index - $dernierIndiceFacture <= 2;
                if ($estDateDocument) {
                    $date = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
                    if (checkdate((int) $m[2], (int) $m[1], (int) $m[3]) && self::datePlausible($date)) {
                        return $date;
                    }
                }
            }
            if (preg_match('/(?:,\s*|\b)(?:le|den|il)\s+(\d{1,2})\s+([a-zà-ÿ]+)\s+(\d{4})\b/iu', $ligne, $m) === 1) {
                $mois = self::mois($m[2]);
                if ($mois !== null) {
                    $date = sprintf('%04d-%02d-%02d', (int) $m[3], $mois, (int) $m[1]);
                    if (checkdate($mois, (int) $m[1], (int) $m[3]) && self::datePlausible($date)) {
                        return $date;
                    }
                }
            }
        }

        return null;
    }

    /** Date documentaire acceptable : du 01.01.1990 à aujourd'hui + 31 jours inclus. */
    public static function datePlausible(?string $date): bool
    {
        if ($date === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }
        $valeur = new \DateTimeImmutable($date);
        $minimum = new \DateTimeImmutable('1990-01-01');
        $maximum = (new \DateTimeImmutable('today'))->modify('+31 days');

        return $valeur >= $minimum && $valeur <= $maximum;
    }

    /**
     * Le titre de la pièce : ligne « Concerne », sinon le numéro (facture, note de crédit, reçu…),
     * sinon type + émetteur + date. Jamais le nom du fichier ni du lot d'ingestion.
     */
    public static function titrePiece(string $texte, ?string $type, ?string $emetteur, ?string $date): ?string
    {
        if (preg_match('/^\s*[#>*_\s]*Concerne\s*:\s*(.+?)\s*$/mi', $texte, $m) === 1) {
            $t = self::nettoyer($m[1]);
            if ($t !== '') {
                return mb_substr($t, 0, 200);
            }
        }
        if (preg_match('/\bN[°o]\s*([A-Z0-9][A-Z0-9\-\/\.]{2,})/u', $texte, $m) === 1) {
            $numero = trim($m[1], " \t\n\r\0\x0B.,-/");
            if ($numero !== '') {
                return mb_substr($numero, 0, 200);
            }
        }
        $morceaux = [];
        foreach ([$type, $emetteur, $date] as $valeur) {
            if ($valeur !== null && trim($valeur) !== '') {
                $morceaux[] = trim($valeur);
            }
        }

        return $morceaux !== [] ? mb_substr(implode(' ', $morceaux), 0, 200) : null;
    }

    private static function contientMot(string $texteNormalise, string $indice): bool
    {
        return $texteNormalise !== '' && str_contains(' ' . $texteNormalise . ' ', ' ' . $indice . ' ');
    }

    private static function nettoyer(string $ligne): string
    {
        $ligne = preg_replace('/[\p{Z}]+/u', ' ', $ligne) ?? $ligne;
        $ligne = preg_replace('/[`*#_>|]+/', ' ', $ligne) ?? '';

        $ligne = trim((string) preg_replace('/\s+/', ' ', $ligne));
        // Débris de reconnaissance au début d'un logo/pied de page : on ne les garde que s'ils
        // précèdent une vraie raison sociale, contrôlée ensuite par candidatEmetteur().
        $ligne = preg_replace('/^(?:(?:\[[^\]]{0,30}\]|\([^)]{0,30}\)|[{}|~^`«»._-]+)\s*|(?:xx|ee|ooo|lll)\s+)+/iu', '', $ligne) ?? $ligne;

        return trim($ligne);
    }

    private static function mois(string $nom): ?int
    {
        static $mois = [
            'janvier' => 1, 'januar' => 1, 'gennaio' => 1,
            'fevrier' => 2, 'februar' => 2, 'febbraio' => 2,
            'mars' => 3, 'marz' => 3, 'marzo' => 3,
            'avril' => 4, 'april' => 4, 'aprile' => 4,
            'mai' => 5, 'maggio' => 5,
            'juin' => 6, 'juni' => 6, 'giugno' => 6,
            'juillet' => 7, 'juli' => 7, 'luglio' => 7,
            'aout' => 8, 'august' => 8, 'agosto' => 8,
            'septembre' => 9, 'september' => 9, 'settembre' => 9,
            'octobre' => 10, 'oktober' => 10, 'ottobre' => 10,
            'novembre' => 11, 'november' => 11,
            'decembre' => 12, 'dezember' => 12, 'dicembre' => 12,
        ];

        return $mois[self::normaliser($nom)] ?? null;
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
        if (str_starts_with($n, 'nom du destinataire')) {
            return false;
        }
        if (preg_match('/^(adresse|adresse principale|la banque se reserve|la banque se reserve le droit|page|si |la |le |les |nous |vous |votre |notre |mon |ma |mes |ton |ta |tes |d |de |du |des |en |pour |avec |a |au |aux |chez |par |sur |donnees relatives)\b/', $n) === 1
            || preg_match('/^(service\s+(?:relation\s+)?client(?:ele|s)?|contact\s+cent(?:er|re)|contact\s+cent(?:er|re)\s+service)\b/', $n) === 1) {
            return false;
        }
        $mots = preg_split('/\s+/', $n) ?: [];
        if (array_filter($mots, static fn (string $mot): bool => !in_array($mot, self::MOTS_EMETTEUR_GENERIQUES, true)) === []) {
            return false;
        }
        if (count(array_filter($mots, static fn (string $mot): bool => strlen($mot) === 1)) >= 2) {
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

        // Une raison sociale, une autorité ou une marque institutionnelle : sans ce signal, une ligne OCR
        // quelconque ne doit pas devenir un dossier du fonds.
        if (preg_match('/\b(sa|sarl|sagl|ag|gmbh|association|fondation|fondazione|commune|comune|canton|service|office|banque|assurance|energie|energies|poste)\b/', $n) !== 1) {
            return false;
        }

        return true;
    }

    /** @return list<string> */
    private static function motsSignificatifs(string $valeur): array
    {
        $mots = array_filter(explode(' ', self::normaliser($valeur)), static function (string $mot): bool {
            return strlen($mot) >= 3 && !in_array($mot, self::MOTS_VIDES, true)
                && !in_array($mot, self::MOTS_EMETTEUR_GENERIQUES, true);
        });
        $mots = array_map(static function (string $mot): string {
            return strlen($mot) > 4 && str_ends_with($mot, 's') ? substr($mot, 0, -1) : $mot;
        }, $mots);

        return array_values(array_unique($mots));
    }
}
