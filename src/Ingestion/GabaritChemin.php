<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use Ged\Socle\Fichiers;

/**
 * Gabarit de nom de fichier : contexte d'un document et rendu d'un chemin relatif.
 *
 * Équivalent GEDv2 de `documents.templating.filepath` : à partir des métadonnées d'un document (titre,
 * correspondant, type, étiquettes, dates, champs personnalisés), on construit le CONTEXTE de gabarit et
 * on rend un chemin relatif. GEDv2 reste fidèle à sa manière : le nom rangé par défaut reste
 * `Fichiers::nomRangé` (titre assaini + extension), le gabarit est une OPTION d'administrateur, et les
 * valeurs manquantes ne produisent jamais un chemin absolu ni une remontée `..`.
 *
 * Ce service porte la partie déterministe, prouvée par les vecteurs : construire les contextes de date
 * de création et d'ajout, d'étiquettes et de champs personnalisés, rendre un gabarit à placeholders
 * (`{titre}` ou `{{ titre }}`) et filtres simples (`lower`, `slugify`, `datetime(...)`), retirer les
 * placeholders vides (« none »), puis ajouter l'extension. Un placeholder inconnu fait échouer le rendu
 * et retombe sur le nom par défaut `0000002.pdf` : c'est le comportement prouvé des vecteurs.
 *
 * Ne couvre pas (listé comme écart dans la note de lot) : le langage d'expressions Jinja de Paperless
 * (`{% if %}`, `{% elif %}`, `or`, arithmétique, `localize_date` multilingue). GEDv2 n'embarque pas de
 * moteur de gabarit généraliste ; ces cas sont hors périmètre de ce service.
 */
final class GabaritChemin
{
    /** Valeur d'un placeholder sans valeur ; rendue telle quelle puis repliée sur « none ». */
    public const VIDE = '-none-';

    /**
     * Chemin relatif rendu pour un document. La clé `gabarit` est le gabarit (ou null : nom par défaut).
     *
     * @param array<string, mixed> $document
     */
    public static function chemin(?string $gabarit, array $document, bool $retirerNone = false, bool $archive = false): string
    {
        $rendu = null;
        if ($gabarit !== null && trim($gabarit) !== '') {
            $chemin = self::rendre($gabarit, self::contexte($document));
            if ($chemin !== null && self::cheminRelatifSur($chemin)) {
                $rendu = self::nettoyerChemin($chemin);
                if (!self::cheminRelatifSur($rendu)) {
                    $rendu = null;
                }
            }
        }
        $extension = self::extension($document, $archive);
        if ($rendu === null) {
            return sprintf('%07d', (int) ($document['pk'] ?? 0)) . $extension;
        }

        if ($retirerNone) {
            $rendu = str_replace('/' . self::VIDE . '/', '/', $rendu);
            $rendu = str_replace(' ' . self::VIDE, '', $rendu);
            $rendu = str_replace(self::VIDE, '', $rendu);
            $rendu = trim($rendu, '/\\');
        }
        $rendu = str_replace(self::VIDE, 'none', $rendu);   // compatibilité du nom affiché

        return $rendu . $extension;
    }

    /**
     * Contexte d'un gabarit : métadonnées de base assainies, dates de création et d'ajout dérivées,
     * étiquettes et champs personnalisés. Les valeurs absentes valent `VIDE` (« -none- »).
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function contexte(array $document): array
    {
        $contexte = [
            'title' => self::assainirGabarit((string) ($document['titre'] ?? '')),
            'correspondent' => isset($document['correspondant'])
                ? self::assainirGabarit((string) $document['correspondant']) : self::VIDE,
            'document_type' => isset($document['type_document'])
                ? self::assainirGabarit((string) $document['type_document']) : self::VIDE,
            'asn' => isset($document['asn']) && $document['asn'] !== null
                ? (string) $document['asn'] : self::VIDE,
            'owner_username' => isset($document['proprietaire'])
                ? (string) $document['proprietaire'] : self::VIDE,
            'original_name' => isset($document['nom_origine']) && $document['nom_origine'] !== ''
                ? pathinfo((string) $document['nom_origine'], PATHINFO_FILENAME) : self::VIDE,
            'doc_pk' => sprintf('%07d', (int) ($document['pk'] ?? 0)),
            'document' => $document,
        ];

        return $contexte
            + self::contexteDate('created', (string) ($document['date_creation'] ?? ''))
            + self::contexteDate('added', (string) ($document['date_ajout'] ?? ''))
            + self::contexteEtiquettes((array) ($document['etiquettes'] ?? []))
            + self::contexteChamps((array) ($document['champs'] ?? []));
    }

    /**
     * Un document témoin, tous champs remplis, pour valider un gabarit avant de l'appliquer aux vrais
     * documents (équivalent `create_dummy_document`).
     *
     * @return array<string, mixed>
     */
    public static function documentTemoin(): array
    {
        return [
            'titre' => 'Sample Title',
            'correspondant' => 'Sample Correspondent',
            'type_document' => 'Sample Type',
            'pk' => 1,
            'asn' => 12345,
            'proprietaire' => 'sample-user',
            'nom_origine' => 'original_file.pdf',
            'mime' => 'application/pdf',
            'date_creation' => '2020-01-02',
            'date_ajout' => '2020-01-02 03:04:05',
            'etiquettes' => ['Test Tag 1', 'Another Test Tag'],
            'champs' => ['Text Custom Field' => ['type' => 'string', 'value' => 'Some String Text']],
        ];
    }

    /**
     * Un chemin rendu reste relatif : vide, ni absolu, ni porteur d'une remontée `..`.
     */
    public static function cheminRelatifSur(string $valeur): bool
    {
        if ($valeur === '') {
            return true;
        }
        if (str_starts_with($valeur, '/') || str_starts_with($valeur, '\\') || preg_match('#^[A-Za-z]:#', $valeur) === 1) {
            return false;
        }

        return !in_array('..', preg_split('#[\\\\/]#', $valeur) ?: [], true);
    }

    /**
     * Rend un gabarit à placeholders. Rend null si un placeholder est inconnu (gabarit invalide) :
     * l'appelant retombe alors sur le nom par défaut, comme Paperless.
     *
     * @param array<string, mixed> $contexte
     */
    public static function rendre(string $gabarit, array $contexte): ?string
    {
        if (!str_contains($gabarit, '{{')) {
            $gabarit = (string) preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', '{{ $1 }}', $gabarit);
        }
        $echec = false;
        $rendu = preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/s', function (array $trouve) use ($contexte, &$echec): string {
            return self::rendrePlaceholder((string) $trouve[1], $contexte, $echec);
        }, $gabarit);
        if ($echec || $rendu === null) {
            return null;
        }

        return $rendu;
    }

    /** @param array<string, mixed> $contexte */
    private static function rendrePlaceholder(string $jeton, array $contexte, bool &$echec): string
    {
        $morceaux = array_map('trim', explode('|', $jeton));
        $expression = (string) array_shift($morceaux);
        $valeur = null;

        if (preg_match('/^(.+?)\s+or\s+(.+)$/', $expression, $trouve) === 1) {
            $valeur = self::lire($trouve[1], $contexte, $echec);
            if ($valeur === null || $valeur === '') {
                $valeur = self::litteral($trouve[2]);
            }
        } else {
            $valeur = self::lire($expression, $contexte, $echec);
        }
        if ($echec) {
            return '';
        }

        foreach ($morceaux as $filtre) {
            $valeur = self::appliquerFiltre($filtre, (string) $valeur, $echec);
            if ($echec) {
                return '';
            }
        }

        return (string) $valeur;
    }

    /** @param array<string, mixed> $contexte */
    private static function lire(string $expression, array $contexte, bool &$echec): ?string
    {
        if (str_contains($expression, '.')) {
            [$racine, $reste] = explode('.', $expression, 2);
            if ($racine !== 'document' || !isset($contexte['document']) || !is_array($contexte['document'])) {
                $echec = true;

                return null;
            }
            $valeur = $contexte['document'][$reste] ?? null;
        } else {
            if (!array_key_exists($expression, $contexte)) {
                $echec = true;   // placeholder inconnu : le rendu échoue, on retombe sur le défaut

                return null;
            }
            $valeur = $contexte[$expression];
        }

        return is_scalar($valeur) ? (string) $valeur : '';
    }

    private static function litteral(string $expression): string
    {
        $expression = trim($expression);
        if (strlen($expression) >= 2 && (
            (str_starts_with($expression, "'") && str_ends_with($expression, "'"))
            || (str_starts_with($expression, '"') && str_ends_with($expression, '"'))
        )) {
            return substr($expression, 1, -1);
        }

        return $expression;
    }

    private static function appliquerFiltre(string $filtre, string $valeur, bool &$echec): string
    {
        if ($filtre === 'lower') {
            return mb_strtolower($valeur);
        }
        if ($filtre === 'slugify') {
            return self::slugifier($valeur);
        }
        if (preg_match('/^datetime\(\s*[\'"](.+?)[\'"]\s*\)$/', $filtre, $trouve) === 1) {
            return self::formaterDate($valeur, $trouve[1]);
        }
        $echec = true;   // filtre hors périmètre : jamais un rendu silencieusement faux

        return '';
    }

    /**
     * Slug compatible avec le `slugify` de Paperless : les apostrophes sont ÉLIDÉES (« John's » →
     * « johns »), pas transformées en séparateur, puis `Fichiers::slug` plie la casse et normalise le
     * reste. Les vecteurs distinguent les deux comportements ; ce service suit celui de Paperless.
     */
    private static function slugifier(string $valeur): string
    {
        $sansApostrophes = str_replace(["'", '’', '‘'], '', $valeur);

        return Fichiers::slug($sansApostrophes);
    }

    /** Convertit les directives strftime usuelles de Paperless en format de date PHP. */
    private static function formaterDate(string $valeur, string $format): string
    {
        $horodatage = strtotime($valeur);
        if ($horodatage === false) {
            return '';
        }
        $traduction = ['%Y' => 'Y', '%y' => 'y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%M' => 'i',
            '%S' => 's', '%B' => 'F', '%b' => 'M', '%j' => 'z', '%p' => 'A'];

        return date(strtr($format, $traduction), $horodatage);
    }

    /** @return array<string, string> */
    private static function contexteDate(string $prefixe, string $date): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $trouve) !== 1) {
            return [$prefixe => self::VIDE];
        }
        [, $annee, $mois, $jour] = $trouve;
        $horodatage = mktime(0, 0, 0, (int) $mois, (int) $jour, (int) $annee) ?: 0;

        return [
            $prefixe => $date,
            $prefixe . '_year' => $annee,
            $prefixe . '_year_short' => substr($annee, 2, 2),
            $prefixe . '_month' => $mois,
            $prefixe . '_month_name' => date('F', $horodatage),
            $prefixe . '_month_name_short' => date('M', $horodatage),
            $prefixe . '_day' => $jour,
        ];
    }

    /**
     * @param array<mixed> $etiquettes
     * @return array<string, mixed>
     */
    private static function contexteEtiquettes(array $etiquettes): array
    {
        $noms = array_values(array_map('strval', $etiquettes));
        $tries = $noms;
        sort($tries, SORT_STRING);

        return [
            'tag_list' => self::assainirGabarit(implode(',', $tries)),
            'tag_name_list' => $noms,
        ];
    }

    /**
     * @param array<string, mixed> $champs nom → {type, value}
     * @return array<string, mixed>
     */
    private static function contexteChamps(array $champs): array
    {
        $normalises = [];
        foreach ($champs as $nom => $definition) {
            $definition = (array) $definition;
            $normalises[self::assainirGabarit((string) $nom)] = [
                'type' => self::assainirGabarit((string) ($definition['type'] ?? 'string')),
                'value' => isset($definition['value']) ? self::assainirGabarit((string) $definition['value']) : null,
            ];
        }

        return ['custom_fields' => $normalises];
    }

    /**
     * Assainissement d'une valeur de gabarit : caractères interdits remplacés par « - » (comme le
     * `replacement_text` de Paperless), points et espaces de fin retirés — « This. is the title. » devient
     * « This. is the title », et ne double jamais l'extension ajoutée ensuite.
     */
    public static function assainirGabarit(string $valeur): string
    {
        $valeur = (string) preg_replace('#[<>:"/\\\\|?*\x00-\x1F]#', '-', $valeur);

        return trim(rtrim($valeur, ". \t"), ' ');
    }

    /** @param array<string, mixed> $document */
    private static function extension(array $document, bool $archive): string
    {
        if ($archive) {
            return '.pdf';
        }

        return RegistreParseurs::extensionDefaut((string) ($document['mime'] ?? ''));
    }

    /**
     * Nettoyage du chemin rendu : plus de saut de ligne, plus d'espace autour des « / », pas de séparateur
     * en tête ni en fin (un gabarit reste toujours relatif).
     */
    private static function nettoyerChemin(string $valeur): string
    {
        $valeur = str_replace(["\n", "\r"], '', $valeur);
        $valeur = (string) preg_replace('#\s*/\s*#', '/', $valeur);

        return trim(trim($valeur), '/\\');
    }
}
