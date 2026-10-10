<?php

declare(strict_types=1);

namespace Ged\Ui;

/**
 * V2-10c / V2-10d / V2-10e — lisibilité d'un extrait OCR. Un encadré de recherche ne doit jamais
 * afficher un amas de symboles, une bouillie de consonnes ni un chapelet de fragments : mieux vaut
 * pas d'extrait qu'un extrait illisible (le titre, le type, l'émetteur et la date suffisent).
 *
 * L'heuristique est volontairement sans dictionnaire général, mais plus stricte que la seule
 * présence de voyelles (un bruit OCR scanne des mots courts qui en portent) :
 *
 *  1. assez de lettres dans le texte (part minimale de lettres) ;
 *  2. une majorité de mots portant une voyelle ;
 *  3. une part notable de « mots » ne doit pas être des fragments de ≤ 3 caractères hors liste de
 *     petits mots courants français, allemands et italiens (et abréviations administratives) ;
 *  4. aucun signe parasite (`{`, `|`, `~`, `#`, `@`, `^`, `\`, `<`, `>`, `_`…) ;
 *  5. au moins un mot de 5 lettres (un vrai extrait en porte un).
 *
 * V2-10e / V2-10f complètent le tableau :
 *
 *  - **tête ET queue** : un extrait mixte est rogné au premier segment propre, et finit sur le
 *    dernier mot plausible de ce segment (une suite de fragments en queue ne s'affiche plus) ;
 *  - **extrait court majoritairement bruité** : sous huit mots, il faut que la majorité des mots
 *    ressemble à quelque chose (mot courant — y compris les mots usuels de la GED —, nombre ou
 *    montant, nom propre à initiale majuscule) ; sinon rien n'est montré ;
 *  - **ponctuation parasite** : une séquence de signes isolés au milieu d'un texte (« ; = »,
 *    « ' . , ») coupe l'extrait ;
 *  - **fenêtre autour du terme** : quand la recherche fournit le terme trouvé (`retenir`), l'extrait
 *    est la fenêtre propre qui contient ce terme, jamais le début du texte.
 *  - **terme insuffisant** : un surlignage ne rend jamais acceptable une courte fenêtre dominée par des
 *    fragments, des signes ou des nombres OCR mal formés ;
 *  - **bornes de mots** : une amorce ou une terminaison de 1–2 lettres hors petits mots usuels est
 *    retirée jusqu'à stabilité ; les groupes de capitales absurdes coupent aussi un passage en deux.
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

    /** V2-10e : en dessous de ce nombre de mots, un extrait doit être majoritairement plausible. */
    private const COURT_MOTS = 12;

    /** V2-10e : au-delà, le « terme » n'est plus un terme de recherche mais le texte entier. */
    private const TERME_MAX = 40;

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
        'mme', 'mr', 'dr', 'pr', 'no', 'num', 'ref', 'obj', 'dos', 'bic', 'ccp', 'iban',
    ];

    /**
     * V2-10e — mots usuels de la GED (pas un dictionnaire général) : ils rendent « lisibles » une
     * en-tête en capitales (`FACTURE`, `CONTRAT`) ou un mot de métadonnée fréquent, pour ne pas
     * rogner à tort un extrait propre et pour juger un extrait court.
     *
     * @var list<string>
     */
    private const MOTS_USUELS = [
        'facture', 'factures', 'facturation', 'contrat', 'contrats', 'convention', 'devis', 'bon',
        'commande', 'commandes', 'livraison', 'paiement', 'payable', 'solde', 'soldes', 'montant',
        'montants', 'total', 'totaux', 'somme', 'date', 'objet', 'reference', 'dossier', 'dossiers',
        'client', 'clients', 'fournisseur', 'fournisseurs', 'adresse', 'numero', 'page', 'pages',
        'annexe', 'annexes', 'echeance', 'societe', 'raison', 'sociale', 'monsieur', 'madame',
        'lettre', 'lettres', 'courrier', 'courriers', 'avis', 'rappel', 'rappels', 'police',
        'assurance', 'assurances', 'expert', 'experts', 'expertise', 'expertises', 'entretien',
        'renouvellement', 'bail', 'locaux', 'immeuble', 'vehicule', 'prestation', 'prestations',
        'honoraires', 'quittance', 'bulletin', 'extrait', 'attestation', 'certificat', 'mandat',
        'ordonnance', 'note', 'rapport', 'compte', 'releve', 'procedure', 'decision', 'resiliation',
        'signature', 'signe', 'paraphe', 'cachet', 'mention', 'conditions', 'generales', 'clause',
        'objet', 'fictif', 'fictive', 'fictifs', 'fictives', 'exemple', 'document', 'documents',
        'copie', 'original', 'version', 'texte', 'contenu', 'service', 'groupe', 'agence',
        'preambule', 'paragraphe', 'maintenance',
    ];

    /** Signes isolés légitimes : un jeton d'un seul de ces signes ne coupe pas le texte. */
    private const SIGNES_BENINS = ['—', '–', ':', '°', '%', '«', '»', '…', '·', '•', '–'];

    /** @var array<string,true>|null */
    private static ?array $petitsMots = null;

    /** @var array<string,true>|null */
    private static ?array $motsUsuels = null;

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
     * Le passage propre de l'extrait, avec ses bornes en octets : le texte entier s'il est propre,
     * la fenêtre rognée sinon, null si aucun segment n'est propre.
     *
     * V2-10e : `$focus` est l'offset du terme trouvé. Quand il est fourni, la fenêtre retournée est
     * celle qui CONTIENT ce terme (la première occurrence propre), jamais le début du texte. Sans
     * focus, on prend le segment propre de tête, queue rognée comprise.
     *
     * @return array{texte:string, debut:int, fin:int}|null
     */
    public static function extraitPropre(string $texte, ?int $focus = null): ?array
    {
        if (trim($texte) === '') {
            return null;
        }

        $jetons = self::jetons($texte);
        $total = count($jetons);
        $runs = self::segmentsPropres($jetons);
        $propreEntier = self::propre($texte);

        if ($runs === []) {
            if ($focus !== null && ($focus < 0 || $focus >= strlen($texte))) {
                return null;
            }
            if ($propreEntier && !self::courtEtBruite($texte)) {
                return self::entier($texte);
            }

            return null;
        }

        // — fenêtre centrée sur le terme trouvé (V2-10e) : elle doit le contenir.
        if ($focus !== null) {
            foreach ($runs as $run) {
                if ($focus < $run['debut'] || $focus >= $run['fin']) {
                    continue;
                }
                [$candidat, $debut, $fin, $coupe] = self::construire($texte, $jetons, $total, $run['i'], $run['j']);
                if ($coupe || $candidat === '' || $focus < $debut || $focus >= $fin
                    || self::contientParasite($candidat) || !self::propre($candidat)
                    || self::courtEtBruite($candidat)) {
                    continue;
                }
                if ($debut === 0 && $fin >= strlen($texte)) {
                    return self::entier($texte);
                }

                return ['texte' => $candidat, 'debut' => $debut, 'fin' => $fin];
            }

            // Le terme n'est dans aucun passage propre (jeton coupé par un chiffre, occurrence noyée
            // dans le bruit) : on retombe sur le segment propre de tête plutôt que de tout masquer.
        }

        // — segment propre de tête, queue rognée : c'est le cas général (corpus, sans terme).
        $premier = $runs[0];
        // Ne jamais franchir un jeton refusé : il peut être un groupe OCR (« CHAG », « EN er ») au
        // milieu. Le premier passage propre est plus utile qu'une phrase dont le bruit réapparaît.
        [$candidat, $debut, $fin, $coupeSignes] = self::construire($texte, $jetons, $total, $premier['i'], $premier['j']);
        if ($candidat === '' || self::contientParasite($candidat) || !self::propre($candidat)) {
            $trouve = null;
            foreach ($runs as $run) {
                [$c, $d, $f, $coupe] = self::construire($texte, $jetons, $total, $run['i'], $run['j']);
                if ($c !== '' && !self::contientParasite($c) && self::propre($c)) {
                    $trouve = [$c, $d, $f, $coupe];
                    break;
                }
            }
            if ($trouve === null) {
                return $propreEntier && !self::courtEtBruite($texte) ? self::entier($texte) : null;
            }
            [$candidat, $debut, $fin, $coupeSignes] = $trouve;
        }

        if ($debut === 0 && $fin >= strlen($texte)) {
            return self::courtEtBruite($texte) ? null : self::entier($texte);
        }

        // Un seul fragment isolé en bord (« Rue » d'une adresse, « Mai » d'une date) reste toléré :
        // l'extrait entier est propre et rien n'a été rogné ; on préfère alors le texte complet.
        $bordsRognes = $premier['rogneTete'] || $premier['rogneQueue'] || $coupeSignes;
        $teteToleree = $premier['i'] === 0
            || ($premier['i'] === 1 && !$jetons[0]['acceptable']);
        $queueToleree = $premier['j'] === $total
            || ($premier['j'] === $total - 1 && !$jetons[$total - 1]['acceptable']);
        if ($propreEntier && !$bordsRognes && $teteToleree && $queueToleree
            && !self::contientParasite(substr($texte, 0, $debut))
            && !self::contientParasite(substr($texte, $fin))) {
            return self::courtEtBruite($texte) ? null : self::entier($texte);
        }

        if (self::courtEtBruite($candidat)) {
            return null;
        }

        return ['texte' => $candidat, 'debut' => $debut, 'fin' => $fin];
    }

    /**
     * Ne retient que les extraits qui portent un passage propre, dans l'ordre, et borne le nombre
     * affiché. Un extrait mixte est rogné au passage propre ; les marques de surlignage des segments
     * conservés sont préservées. V2-10e : la fenêtre retenue contient le premier terme trouvé.
     *
     * @param list<list<mixed>> $extraits entrée tolérée mal formée : les segments non-tableaux sont ignorés
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
            $propre = self::extraitPropre($texte, self::focusTerme($segments, $texte));
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
     * Offset du premier terme trouvé — un terme de recherche est court, sinon c'est le texte entier
     * (segment fabriqué à la main) et l'on ne centre pas.
     *
     * @param list<array{t:string, hit:bool}> $segments
     */
    private static function focusTerme(array $segments, string $texte): ?int
    {
        $offset = 0;
        foreach ($segments as $segment) {
            $longueur = strlen($segment['t']);
            if ($segment['hit'] && $longueur > 0 && $longueur <= self::TERME_MAX && $longueur < strlen($texte)) {
                return $offset;
            }
            $offset += $longueur;
        }

        return null;
    }

    /**
     * Fenêtre [debut, fin) construite sur la suite de mots [i, j[ : jusqu'au mot suivant, en gardant
     * la ponctuation et les chiffres collés (« 2026. », « … »), mais jamais en franchissant un signe
     * parasite. Une séquence de signes isolés au milieu coupe l'extrait (V2-10e).
     *
     * @param list<array{t:string, lettres:string, debut:int, fin:int, longueur:int, acceptable:bool}> $jetons
     * @return array{0:string, 1:int, 2:int, 3:bool}
     */
    private static function construire(string $texte, array $jetons, int $total, int $i, int $j): array
    {
        $debut = $jetons[$i]['debut'];
        $limite = $j < $total ? $jetons[$j]['debut'] : strlen($texte);
        $fin = $jetons[$j - 1]['fin'];
        while ($fin < $limite && !self::parasiteEn($texte, $fin)) {
            $fin++;
        }
        $candidat = rtrim(substr($texte, $debut, $fin - $debut));
        // Une ponctuation isolée collée en bord (guillemet, apostrophe) n'appartient pas au texte.
        $candidat = rtrim($candidat, "\t\n\r\0\x0B\"'");
        $fin = $debut + strlen($candidat);

        // Une fenêtre qui s'arrête juste après un numéro ne doit pas finir sur « ce », « er », etc. :
        // ce sont les deux dernières lettres d'un mot que le découpeur a tronqué. Les petits mots
        // usuels restent permis ailleurs, mais pas dans cette position sans frontière sémantique.
        $coupeMot = preg_match('/^(.*\p{N}[^\p{L}\p{N}]+)\p{L}{1,2}$/us', $candidat, $bordCoupe) === 1;
        if ($coupeMot) {
            $candidat = rtrim($bordCoupe[1]);
            $fin = $debut + strlen($candidat);
        }

        $coupe = self::trouverSignesIsoles($candidat);
        if ($coupe !== null) {
            $candidat = rtrim(substr($candidat, 0, $coupe));
            $candidat = rtrim($candidat, "\t\n\r\0\x0B\"'");
            $fin = $debut + strlen($candidat);

            return [$candidat, $debut, $fin, true];
        }

        return [$candidat, $debut, $fin, $coupeMot];
    }

    /**
     * Segments propres : suites maximales de mots acceptables, dont la tête et la queue sont élaguées
     * des jetons manifestement bruités (majuscules criardes, amas de voyelles, mots sans voyelle,
     * majuscules internes). V2-10f : un jeton implausible coupe vraiment le passage, y compris au
     * milieu ; sinon « Post CHAG Martin » laisse le tampon OCR affiché.
     *
     * @param list<array{t:string, lettres:string, debut:int, fin:int, longueur:int, acceptable:bool}> $jetons
     * @return list<array{i:int, j:int, debut:int, fin:int, rogneTete:bool, rogneQueue:bool}>
     */
    private static function segmentsPropres(array $jetons): array
    {
        $total = count($jetons);
        $segments = [];
        $i = 0;
        while ($i < $total) {
            if (self::implausible($jetons[$i])) {
                $i++;
                continue;
            }
            $j = $i;
            while ($j < $total && !self::implausible($jetons[$j])) {
                $j++;
            }
            [$a, $b, $rogneTete, $rogneQueue] = self::rognerBords($jetons, $i, $j);
            if ($b - $a >= self::SEGMENT_MIN_MOTS) {
                $lettres = 0;
                $maxLettres = 0;
                for ($k = $a; $k < $b; $k++) {
                    $lettres += $jetons[$k]['longueur'];
                    $maxLettres = max($maxLettres, $jetons[$k]['longueur']);
                }
                if ($lettres >= self::SEGMENT_MIN_LETTRES && $maxLettres >= self::LONGUEUR_MOT) {
                    $segments[] = [
                        'i' => $a, 'j' => $b,
                        'debut' => $jetons[$a]['debut'], 'fin' => $jetons[$b - 1]['fin'],
                        'rogneTete' => $rogneTete, 'rogneQueue' => $rogneQueue,
                    ];
                }
            }
            $i = $j;
        }

        return $segments;
    }

    /**
     * V2-10e — élague la tête et la queue d'un segment : les jetons manifestement bruités, et, une
     * fois qu'un bruit de bord a été trouvé, les petits mots non usuels qui l'accompagnent
     * (« ieee Post CHAG » : Majuscule courte « Post » entre deux fragments).
     *
     * @param list<array{t:string, lettres:string, debut:int, fin:int, longueur:int, acceptable:bool}> $jetons
     * @return array{0:int, 1:int, 2:bool, 3:bool}
     */
    private static function rognerBords(array $jetons, int $i, int $j): array
    {
        $a = $i;
        $b = $j;
        $rogneTete = false;
        $rogneQueue = false;
        $bruit = $i > 0 && self::implausible($jetons[$i - 1]) ? 1 : 0;
        while ($a < $b && (self::implausible($jetons[$a]) || self::fragmentDeBord($jetons[$a])
            || ($bruit > 0 && self::courtNonUsuel($jetons[$a])))) {
            if (self::implausible($jetons[$a]) || self::fragmentDeBord($jetons[$a])) {
                $bruit++;
            }
            $a++;
            $rogneTete = true;
        }
        $bruit = $j < count($jetons) && self::implausible($jetons[$j]) ? 1 : 0;
        while ($b > $a && (self::implausible($jetons[$b - 1]) || self::fragmentDeBord($jetons[$b - 1])
            || ($bruit > 0 && self::courtNonUsuel($jetons[$b - 1])))) {
            if (self::implausible($jetons[$b - 1]) || self::fragmentDeBord($jetons[$b - 1])) {
                $bruit++;
            }
            $b--;
            $rogneQueue = true;
        }

        return [$a, $b, $rogneTete, $rogneQueue];
    }

    /** V2-10f — un fragment de 1–2 lettres ne reste en bord que s'il est vraiment usuel dans une phrase. */
    private static function fragmentDeBord(array $jeton): bool
    {
        if ($jeton['longueur'] > 2) {
            return false;
        }

        return !in_array(self::sansAccents(mb_strtolower($jeton['lettres'])), [
            'a', 'de', 'du', 'le', 'la', 'les', 'en', 'et', 'un', 'au', 'ce', 'se', 'ne',
            'si', 'ou', 'on', 'il', 'el', 'di', 'da', 'in', 'im', 'am', 'an', 'zu', 'so', 'es',
            'ch', 'sa', 'ag', 'ht',
        ], true);
    }

    /** V2-10f — un mot court non usuel accompagne volontiers un bruit déjà trouvé en bord. */
    private static function courtNonUsuel(array $jeton): bool
    {
        return $jeton['longueur'] <= 4 && !self::motUsuel($jeton['lettres']);
    }

    /**
     * V2-10e — vrai si l'extrait est court et majoritairement fait de bruit. Un mot compte comme
     * plausible s'il est un petit mot connu, un mot usuel de la GED, un nombre ou un montant, ou un
     * nom propre plausible (initiale majuscule suivie de minuscules). Sous huit mots, la moitié au
     * moins doit être plausible, sinon rien ne s'affiche.
     */
    private static function courtEtBruite(string $texte): bool
    {
        $unites = preg_split('/\s+/u', trim($texte)) ?: [];
        $votes = [];
        foreach ($unites as $unite) {
            $mot = trim((string) $unite, ".,;:!?()[]{}«»\"'…-–—");
            if ($mot === '') {
                continue;
            }
            if (preg_match('/\p{N}/u', $mot) === 1) {
                // Un montant ou une date est légitime ; « 29.90/22 » est au contraire une couture
                // OCR qui ne peut pas servir de vote favorable.
                $votes[] = !str_contains($mot, '/');
                continue;
            }
            if (preg_match('/\p{L}/u', $mot) !== 1) {
                continue;                    // signe pur
            }
            $jeton = self::jetonDepuisMot($mot);
            $votes[] = !self::fragmentDeBord($jeton) && !self::implausible($jeton);
        }
        $n = count($votes);
        if ($n >= self::COURT_MOTS) {
            return false;
        }
        $plausibles = count(array_filter($votes));

        // À égalité, le seul mot recherché pourrait artificiellement « sauver » la fenêtre. Il faut
        // donc une majorité stricte de votes plausibles, indépendamment du surlignage.
        return $plausibles * 2 <= $n;
    }

    /** Construit un jeton minimal à partir d'un mot, pour les jugements de plausibilité. */
    private static function jetonDepuisMot(string $mot): array
    {
        $lettres = (string) preg_replace('/[^\p{L}]/u', '', $mot);
        $longueur = mb_strlen($lettres);

        return [
            't' => $mot,
            'lettres' => $lettres,
            'debut' => 0,
            'fin' => strlen($mot),
            'longueur' => $longueur,
            'acceptable' => $longueur > self::LONGUEUR_FRAGMENT || self::petitMot($lettres),
        ];
    }

    /**
     * V2-10f — vrai si le mot est manifestement un artefact : fragment, tout en majuscules non usuel,
     * majuscule interne, amas de voyelles ou mot sans voyelle.
     */
    private static function implausible(array $jeton): bool
    {
        if ($jeton['longueur'] === 0) {
            return true;
        }
        if (str_contains($jeton['t'], '-')) {
            return false;
        }
        if (($jeton['bruitMele'] ?? false) === true) {
            return true;
        }
        if (!$jeton['acceptable']) {
            // Un mot de trois lettres isolé (« Rue », « Mai ») est compté comme fragment par
            // `propre()`, sans devenir pour autant une coupure dure : sinon une adresse saine est
            // tronquée dès sa première préposition.
            $minuscule = mb_strtolower($jeton['lettres']);
            $sansVoyelle = preg_match('/[aeiouyàâäéèêëîïôöùûüœæ]/iu', $jeton['lettres']) !== 1;
            $sigle = in_array(mb_strtoupper($jeton['lettres']), ['SA', 'AG', 'HT', 'CH', 'TVA', 'TTC', 'CHF', 'EUR'], true);

            return !$sigle && $sansVoyelle && ($jeton['t'] === $minuscule || $jeton['t'] === mb_strtoupper($jeton['t']));
        }
        $toutMajuscules = $jeton['t'] === mb_strtoupper($jeton['t']);
        $sigleAdmis = in_array($jeton['lettres'], ['SA', 'AG', 'HT', 'CH'], true);
        // EN isolé entre des fragments n'est pas le mot allemand « en » : la casse est ici une trace
        // du tampon OCR. Les sigles administratifs usuels restent, eux, explicitement admis.
        if ($toutMajuscules && $jeton['longueur'] <= 2
            && !$sigleAdmis) {
            return true;
        }
        if ($jeton['longueur'] <= 2 && !$sigleAdmis && $jeton['t'] !== mb_strtolower($jeton['t']) && self::fragmentDeBord($jeton)) {
            return true;
        }
        if (self::motUsuel($jeton['lettres'])) {
            return false;
        }
        $minuscule = mb_strtolower($jeton['lettres']);
        // « xécution », « xxve » et « Qxec » : signatures synthétiques de mot coupé / OCR, pas des
        // débuts de phrase plausibles. On ne généralise pas aux mots commençant par une consonne.
        if (preg_match('/^(?:x[éèê]|xx|qx)/u', $minuscule) === 1) {
            return true;
        }
        if ($toutMajuscules) {
            return true;
        }
        $suite = mb_substr($jeton['t'], 1);
        // Une courte majuscule interne (« PostC ») est un collage OCR ; un identifiant de recherche
        // long peut en revanche être légitime (référence ou nom composé) et ne doit pas faire perdre
        // le passage propre qui le porte.
        if ($jeton['longueur'] <= 8 && $suite !== '' && preg_match('/\p{Lu}/u', $suite) === 1) {
            return true;
        }
        if (preg_match('/[aeiouyàâäéèêëîïôöùûüœæ]{4,}/iu', $jeton['lettres']) === 1) {
            return true;
        }

        return preg_match('/[aeiouyàâäéèêëîïôöùûüœæ]/iu', $jeton['lettres']) !== 1;
    }

    /**
     * V2-10e — première séquence de signes isolés au milieu du texte, ou null. Renvoie l'offset du
     * premier signe : la séquence retire ce qui suit (« ; = », « ' . , », apostrophe ou guillemet
     * collé en fin de mot).
     */
    private static function trouverSignesIsoles(string $texte): ?int
    {
        $trouves = preg_match_all('/\S+/u', $texte, $unites, PREG_OFFSET_CAPTURE);
        if ($trouves === false || $trouves === 0) {
            return null;
        }
        $precedent = null;
        foreach ($unites[0] as [$unite, $offset]) {
            if (!self::uniteSuspecte((string) $unite)) {
                $precedent = null;
                continue;
            }
            if (preg_match('/\p{L}|\p{N}/u', (string) $unite) === 1
                && preg_match('/[;=\'\"]$/u', (string) $unite) === 1) {
                return (int) $offset;
            }
            if ($precedent !== null) {
                return $precedent;
            }
            $precedent = (int) $offset;
        }

        return null;
    }

    /** V2-10e — vrai si ce jeton est une ponctuation parasite (isolée ou collée anormalement). */
    private static function uniteSuspecte(string $unite): bool
    {
        if (preg_match('/\p{L}|\p{N}/u', $unite) === 1) {
            // Un mot ou un nombre : suspect seulement si un signe fort y est collé à la fin.
            return preg_match('/[;=\'"]$/u', $unite) === 1;
        }
        // Points de suspension (« … » ou « ... ») : légitimes ; un point ou une virgule SEULS, non.
        if (preg_match('/^[.…]+$/u', $unite) === 1 && (mb_strlen($unite) >= 2 || $unite === '…')) {
            return false;
        }
        if (in_array($unite, self::SIGNES_BENINS, true)) {
            return false;
        }

        return true;
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
            if (!$jeton['acceptable'] || self::implausible($jeton)) {
                $fragments++;
            }
            if (self::implausible($jeton)) {
                return false;
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
        $melanges = preg_match_all('/\p{Ll}+\p{N}+\p{Lu}+/u', $texte, $trouvesMelanges, PREG_OFFSET_CAPTURE);
        $zonesMelangees = [];
        if ($melanges !== false) {
            // preg_match_all pose toujours la clé 0 (au pire une liste vide) : le repli était mort.
            foreach ($trouvesMelanges[0] as [$melange, $offset]) {
                $zonesMelangees[] = [(int) $offset, (int) $offset + strlen($melange)];
            }
        }
        $jetons = [];
        foreach ($mots[0] as [$mot, $debut]) {
            $lettres = (string) preg_replace('/[^\p{L}]/u', '', $mot);
            $longueur = mb_strlen($lettres);
            $melange = false;
            foreach ($zonesMelangees as [$zoneDebut, $zoneFin]) {
                if ($debut < $zoneFin && $debut + strlen($mot) > $zoneDebut) {
                    $melange = true;
                    break;
                }
            }
            $jetons[] = [
                't' => $mot,
                'lettres' => $lettres,
                'debut' => $debut,
                'fin' => $debut + strlen($mot),
                'longueur' => $longueur,
                'acceptable' => !$melange && ($longueur > self::LONGUEUR_FRAGMENT || self::petitMot($lettres)),
                'bruitMele' => $melange,
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

    /** V2-10e — un mot usuel de la GED (ou un petit mot) compte comme mot courant. */
    private static function motUsuel(string $mot): bool
    {
        $cle = self::sansAccents(mb_strtolower($mot));
        if (self::petitMot($mot)) {
            return true;
        }
        if (self::$motsUsuels === null) {
            self::$motsUsuels = array_fill_keys(array_map(self::sansAccents(...), self::MOTS_USUELS), true);
        }

        return isset(self::$motsUsuels[$cle]);
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

    /** @return array{texte:string, debut:int, fin:int} */
    private static function entier(string $texte): array
    {
        return ['texte' => $texte, 'debut' => 0, 'fin' => strlen($texte)];
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
}
