<?php

declare(strict_types=1);

namespace Ged\Workflows;

use Ged\Classement\Correspondance;

/**
 * Déclencheur de workflow, à la manière de Paperless-ngx 2.20 (`WorkflowTrigger`) : le TYPE (consommation,
 * document ajouté, document mis à jour, planifié) et les FILTRES (chemin, nom de fichier, source, dossier,
 * étiquettes, type, émetteur, règles de courrier, correspondance de contenu, requête de champs
 * personnalisés, planification).
 *
 * La correspondance textuelle réutilise le service de ged-parite-comportement-1
 * (`Ged\Classement\Correspondance`) : les modes `MATCH_*` de Paperless y sont déjà implémentés à la
 * manière de GEDv2 ; on ne réinvente pas un second moteur de correspondance.
 *
 * Les noms des filtres restent ceux de Paperless (`filter_*`, `matching_algorithm`, `sources`) : c'est ce
 * que portent les vecteurs extraits en lecture seule des tests Paperless, et cela rend le rejeu lisible.
 */
final class Declencheur
{
    public const CONSOMMATION = 1;
    public const AJOUT = 2;
    public const MISE_A_JOUR = 3;
    public const PLANIFIE = 4;

    public const MATCH_NONE = 0;
    public const MATCH_ANY = 1;
    public const MATCH_ALL = 2;
    public const MATCH_LITERAL = 3;
    public const MATCH_REGEX = 4;
    public const MATCH_FUZZY = 5;
    public const MATCH_AUTO = 6;

    public const SOURCE_DOSSIER = 1;    // DocumentSource.ConsumeFolder
    public const SOURCE_API = 2;        // DocumentSource.ApiUpload
    public const SOURCE_COURRIEL = 3;   // DocumentSource.MailFetch
    public const SOURCE_ECRAN = 4;      // DocumentSource.WebUI

    public const DATE_AJOUT = 'added';
    public const DATE_CREATION = 'created';
    public const DATE_MODIFICATION = 'modified';
    public const DATE_CHAMP = 'custom_field';

    /** @var array<int, string> */
    public const TYPES = [
        self::CONSOMMATION => 'consommation',
        self::AJOUT => 'document_ajoute',
        self::MISE_A_JOUR => 'document_mis_a_jour',
        self::PLANIFIE => 'planifie',
    ];

    /** @var array<int, string> */
    public const MATCHING = [
        self::MATCH_NONE => 'MATCH_NONE',
        self::MATCH_ANY => 'MATCH_ANY',
        self::MATCH_ALL => 'MATCH_ALL',
        self::MATCH_LITERAL => 'MATCH_LITERAL',
        self::MATCH_REGEX => 'MATCH_REGEX',
        self::MATCH_FUZZY => 'MATCH_FUZZY',
        self::MATCH_AUTO => 'MATCH_AUTO',
    ];

    /** @var array<int, string> */
    public const SOURCES = [
        self::SOURCE_DOSSIER => 'consume_folder',
        self::SOURCE_API => 'api_upload',
        self::SOURCE_COURRIEL => 'mail_fetch',
        self::SOURCE_ECRAN => 'web_ui',
    ];

    /** @var array<int, string> */
    public const DATES = [
        self::DATE_AJOUT => 'Ajout',
        self::DATE_CREATION => 'Création',
        self::DATE_MODIFICATION => 'Modification',
        self::DATE_CHAMP => 'Champ personnalisé',
    ];

    /**
     * Un déclencheur de ce type correspond-il à ce document ? Les déclencheurs d'un workflow sont
     * examinés dans l'ordre ; le premier qui correspond suffit.
     *
     * @param array<string, mixed> $trigger
     * @param array<string, mixed> $document
     */
    public static function correspondType(int $type, array $trigger, array $document): bool
    {
        return match ($type) {
            self::CONSOMMATION => self::correspondConsommation($trigger, $document),
            self::AJOUT, self::MISE_A_JOUR, self::PLANIFIE => self::correspondExistant($trigger, $document),
            default => false,
        };
    }

    /**
     * Filtres propres à l'entrée par consommation : source, règle de courrier, nom de fichier, chemin.
     *
     * @param array<string, mixed> $trigger
     * @param array<string, mixed> $document
     */
    public static function correspondConsommation(array $trigger, array $document): bool
    {
        $sources = array_values(array_map('intval', (array) ($trigger['sources'] ?? [])));
        if ($sources !== [] && !in_array((int) ($document['source'] ?? 0), $sources, true)) {
            return false;
        }
        $mailrule = $trigger['filter_mailrule'] ?? null;
        if ($mailrule !== null && (int) ($document['mailrule'] ?? 0) !== (int) $mailrule) {
            return false;
        }
        if (!self::nomFichierCorrespond((string) ($trigger['filter_filename'] ?? ''), (string) ($document['nom_fichier'] ?? ''))) {
            return false;
        }

        return self::cheminCorrespond((string) ($trigger['filter_path'] ?? ''), (string) ($document['chemin'] ?? ''));
    }

    /**
     * Filtres propres à un document déjà présent : correspondance de contenu, étiquettes, émetteur, type,
     * dossier, requête de champs personnalisés, nom d'origine.
     *
     * @param array<string, mixed> $trigger
     * @param array<string, mixed> $document
     */
    public static function correspondExistant(array $trigger, array $document): bool
    {
        $algorithme = (int) ($trigger['matching_algorithm'] ?? self::MATCH_NONE);
        if ($algorithme > self::MATCH_NONE
            && !Correspondance::correspond(
                self::MATCHING[$algorithme] ?? 'MATCH_NONE',
                (string) ($trigger['match'] ?? ''),
                (string) ($document['contenu'] ?? ''),
                (bool) ($trigger['is_insensitive'] ?? true),
            )) {
            return false;
        }

        $etiquettes = self::normaliserListe((array) ($document['etiquettes'] ?? []));
        foreach ([
            'filter_has_tags' => 'intersection',
            'filter_has_all_tags' => 'inclusion',
            'filter_has_not_tags' => 'exclusion',
        ] as $cle => $mode) {
            $attendus = self::normaliserListe((array) ($trigger[$cle] ?? []));
            if ($attendus === []) {
                continue;
            }
            $intersection = array_intersect($attendus, $etiquettes);
            $ok = match ($mode) {
                'intersection' => $intersection !== [],
                'inclusion' => array_diff($attendus, $etiquettes) === [],
                'exclusion' => $intersection === [],
            };
            if (!$ok) {
                return false;
            }
        }

        return self::valeurCorrespond($trigger['filter_has_correspondent'] ?? null, $document['emetteur'] ?? null)
            && !self::valeurExclue($trigger['filter_has_not_correspondents'] ?? [], $document['emetteur'] ?? null)
            && self::valeurCorrespond($trigger['filter_has_document_type'] ?? null, $document['type'] ?? null)
            && !self::valeurExclue($trigger['filter_has_not_document_types'] ?? [], $document['type'] ?? null)
            && self::valeurCorrespond($trigger['filter_has_storage_path'] ?? null, $document['dossier'] ?? null)
            && !self::valeurExclue($trigger['filter_has_not_storage_paths'] ?? [], $document['dossier'] ?? null)
            && self::champsCorrespondent($trigger['filter_custom_field_query'] ?? null, (array) ($document['champs'] ?? []))
            && self::nomFichierCorrespond((string) ($trigger['filter_filename'] ?? ''), (string) ($document['nom_fichier'] ?? ''));
    }

    /**
     * Déclencheur planifié : la date retenue (`schedule_date_field`) décalée de `schedule_offset_days`
     * est atteinte, et — si récurrent — le délai de récurrence est écoulé depuis la dernière exécution.
     *
     * @param array<string, mixed> $trigger
     * @param array<string, mixed> $document
     */
    public static function planifie(
        array $trigger,
        array $document,
        \DateTimeImmutable $maintenant,
        ?\DateTimeImmutable $derniereExecution = null,
    ): bool {
        $date = self::dateCible($trigger, $document);
        if ($date === null) {
            return false;
        }
        $decalage = (int) ($trigger['schedule_offset_days'] ?? 0);
        $cible = $date->modify(($decalage >= 0 ? '+' : '') . $decalage . ' days');
        if ($cible > $maintenant) {
            return false;
        }
        if (($trigger['schedule_is_recurring'] ?? false) === true && $derniereExecution !== null) {
            $intervalle = max(1, (int) ($trigger['schedule_recurring_interval_days'] ?? 1));
            if ($derniereExecution->modify('+' . $intervalle . ' days') > $maintenant) {
                return false;
            }
        }

        return true;
    }

    /**
     * Date de référence du déclencheur planifié : un champ de date du document, ou un champ personnalisé.
     *
     * @param array<string, mixed> $trigger
     * @param array<string, mixed> $document
     */
    public static function dateCible(array $trigger, array $document): ?\DateTimeImmutable
    {
        $champ = (string) ($trigger['schedule_date_field'] ?? self::DATE_AJOUT);
        $brut = match ($champ) {
            self::DATE_CREATION => ($document['dates'] ?? [])['created'] ?? null,
            self::DATE_MODIFICATION => ($document['dates'] ?? [])['modified'] ?? null,
            self::DATE_CHAMP => (($document['champs'] ?? [])[(string) ($trigger['schedule_date_custom_field'] ?? '')] ?? null),
            default => ($document['dates'] ?? [])['added'] ?? null,
        };
        if (!is_string($brut) || preg_match('#^\d{4}-\d{2}-\d{2}#', $brut) !== 1) {
            return null;
        }
        try {
            return (new \DateTimeImmutable(substr($brut, 0, 10)))->setTime(0, 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Un filtre « présence » vide/absent ne contraint pas ; sinon la valeur du document doit être égale. */
    private static function valeurCorrespond(mixed $attendu, mixed $valeur): bool
    {
        if ($attendu === null || $attendu === '') {
            return true;
        }

        return self::normaliser((string) $attendu) === self::normaliser((string) $valeur);
    }

    /**
     * @param mixed $exclus
     */
    private static function valeurExclue(mixed $exclus, mixed $valeur): bool
    {
        $liste = self::normaliserListe((array) $exclus);
        if ($liste === []) {
            return false;
        }

        return in_array(self::normaliser((string) $valeur), $liste, true);
    }

    private static function nomFichierCorrespond(string $motif, string $nom): bool
    {
        if (trim($motif) === '') {
            return true;
        }
        if ($nom === '') {
            return true;   // le nom d'origine n'est pas connu : Paperless ne filtre alors pas
        }

        return fnmatch(mb_strtolower($motif), mb_strtolower($nom));
    }

    private static function cheminCorrespond(string $motif, string $chemin): bool
    {
        if (trim($motif) === '') {
            return true;
        }
        if ($chemin === '') {
            return false;
        }

        return fnmatch(str_replace('\\', '/', $motif), str_replace('\\', '/', $chemin));
    }

    /**
     * Requête de champs personnalisés, forme GEDv2 minimale `champ=operateur:valeur` (plusieurs séparées
     * par des virgules). La forme JSON encodée de Paperless n'est pas rejouée : elle est listée
     * `non_rejouables` par l'extracteur, la requête structurée n'ayant pas de modèle GEDv2.
     *
     * @param array<string, mixed> $champs
     */
    private static function champsCorrespondent(mixed $requete, array $champs): bool
    {
        if (!is_string($requete) || trim($requete) === '') {
            return true;
        }
        foreach (explode(',', $requete) as $morceau) {
            if (preg_match('/^\s*([^=]+)=(egal|different|contient|superieur|inferieur|non_vide)\s*:\s*(.*)$/u', $morceau, $t) !== 1) {
                return false;   // requête illisible : Paperless refuse aussi (ValidationError)
            }
            $valeur = $champs[trim($t[1])] ?? null;
            $condition = ['champ' => 'x', 'operateur' => $t[2], 'valeur' => trim($t[3])];
            if (!Conditions::satisfait($condition, ['x' => $valeur])) {
                return false;
            }
        }

        return true;
    }

    /** @param list<mixed> $liste @return list<string> */
    private static function normaliserListe(array $liste): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $valeur): string => self::normaliser((string) $valeur), $liste),
            static fn (string $valeur): bool => $valeur !== '',
        )));
    }

    private static function normaliser(string $valeur): string
    {
        return mb_strtolower(trim($valeur), 'UTF-8');
    }
}
