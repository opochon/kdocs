<?php

declare(strict_types=1);

namespace Ged\Socle;

/**
 * Gestes fichiers du fonds : assainissement Windows des segments, empreintes, MIME, JSON canonique.
 * Portage des règles prouvées de GEDv1 (StoragePathGenerator::sanitizePathSegment), avec le trou
 * bureautique comblé : xls, xlsx, odt, rtf ont leur MIME (ils n'étaient jamais indexés).
 */
final class Fichiers
{
    /** MIME par extension, AVANT toute détection de contenu : déterministe et portable, l'export y gagne. */
    private const MIME = [
        'pdf' => 'application/pdf',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheetml.sheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'rtf' => 'application/rtf', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'eml' => 'message/rfc822', 'msg' => 'application/vnd.ms-outlook',
        'json' => 'application/json', 'md' => 'text/markdown',
    ];

    /** Fichiers d'appoint des systèmes de fichiers : jamais des documents, quelle que soit la plateforme. */
    private const POUBELLE_OS = ['thumbs.db', 'desktop.ini', '.ds_store'];

    /** JSON identique à chaque encodage : c'est lui qui rend l'export reconstructible stable. */
    public const DRAPEAUX_JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Un segment de chemin assaini pour Windows : caractères interdits remplacés (un correspondant
     * « A / B » ne crée jamais deux niveaux), espaces soulignés, ni point final ni nom réservé (CON, PRN…).
     */
    public static function assainirSegment(string $segment): string
    {
        $segment = preg_replace('#[<>:"|?*/\\\\\x00-\x1F]#', '_', $segment) ?? '_';
        $segment = preg_replace('/\s+/', ' ', $segment) ?? '_';
        $segment = rtrim(trim(str_replace(' ', '_', trim($segment))), ". \t");
        $segment = mb_substr($segment, 0, 100);
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $segment)) {
            $segment .= '_';
        }

        return $segment !== '' ? $segment : 'Divers';
    }

    /** Nom de fichier rangé : titre assaini + extension d'origine en minuscules. */
    public static function nomRangé(string $titre, string $extension): string
    {
        $nom = self::assainirSegment($titre);
        $extension = strtolower(trim($extension, '.'));

        return $extension !== '' ? "$nom.$extension" : $nom;
    }

    /** sha256 du contenu : c'est lui qui relie une ligne de l'index à son fichier, même déplacé. */
    public static function empreinte(string $fichier): string
    {
        $h = hash_file('sha256', $fichier);
        if ($h === false) {
            throw new \RuntimeException("empreinte : impossible de lire $fichier");
        }

        return $h;
    }

    public static function mime(string $fichier): string
    {
        $extension = strtolower(pathinfo($fichier, PATHINFO_EXTENSION));
        if (isset(self::MIME[$extension])) {
            return self::MIME[$extension];
        }

        return (new \finfo(FILEINFO_MIME_TYPE))->file($fichier) ?: 'application/octet-stream';
    }

    /** Un chemin interne du fonds (point initial, annexe d'OS, temporaire) n'est jamais un document. */
    public static function estInterne(string $nom): bool
    {
        return str_starts_with($nom, '.')
            || in_array(strtolower($nom), self::POUBELLE_OS, true)
            || str_starts_with($nom, '~$')
            || str_ends_with(strtolower($nom), '.tmp') || str_ends_with(strtolower($nom), '.part');
    }

    /** JSON canonique : lisible (indenté) pour un fichier à côté du document, compact pour l'index. */
    public static function json(array $valeurs, bool $fichier = false): string
    {
        return json_encode($valeurs, self::DRAPEAUX_JSON | ($fichier ? JSON_PRETTY_PRINT : 0));
    }

    /**
     * Colonnes de l'index dérivées des métadonnées : [entêtes, valeurs] prêtes au INSERT/UPDATE.
     * La date qui ne dit pas Y-m-d n'entre pas dans la colonne DATE (elle reste dans le .meta.json).
     */
    public static function colonnesIndex(array $meta): array
    {
        $etiquettes = array_values(array_filter(array_map('strval', (array) ($meta['etiquettes'] ?? [])),
            static fn (string $e) => $e !== ''));

        return [
            ['titre', 'type', 'emetteur', 'date_document', 'montant', 'etiquettes', 'statut', 'source'],
            [mb_substr((string) $meta['titre'], 0, 200), $meta['type'] ?? null, $meta['emetteur'] ?? null,
                (isset($meta['date']) && preg_match('#^\d{4}-\d{2}-\d{2}$#', (string) $meta['date'])) ? $meta['date'] : null,
                isset($meta['montant']) && is_numeric($meta['montant']) ? (float) $meta['montant'] : null,
                $etiquettes === [] ? null : self::json($etiquettes), $meta['statut'] ?? 'a_controler',
                $meta['source'] ?? null],
        ];
    }

    /** Métadonnées minimales d'un fichier posé à la main, sans `.meta.json` : son nom lui sert de titre. */
    public static function metaMinimale(string $nomFichier): array
    {
        return ['titre' => pathinfo($nomFichier, PATHINFO_FILENAME)];
    }

    /**
     * Un nom encore libre dans un dossier : « nom », « nom-2 », « nom-3 »… Le premier essai ne
     * regarde ni `.meta.json` ni `.versions/` : ils suivent leur fichier, ils n'occupent pas un nom.
     */
    public static function nomDisponible(string $dossier, string $nom): string
    {
        if (!is_file("$dossier/$nom")) {
            return $nom;
        }
        $base = pathinfo($nom, PATHINFO_FILENAME);
        $extension = pathinfo($nom, PATHINFO_EXTENSION);
        for ($i = 2; ; $i++) {
            $essai = $extension !== '' ? "$base-$i.$extension" : "$base-$i";
            if (!is_file("$dossier/$essai")) {
                return $essai;
            }
        }
    }

    /** Dossier `.versions/` de l'année : `.versions/<fichier>/` à côté du document. */
    public static function dossierVersions(string $cheminAbsoluDocument): string
    {
        return dirname($cheminAbsoluDocument) . '/.versions/' . basename($cheminAbsoluDocument);
    }

    /** Copie une archive de version : `vNNN_<empreinte8>.<ext>`, adressée par contenu (deux contenus égaux ne doublonnent pas). */
    public static function archiver(string $cheminAbsoluDocument, int $numero, string $empreinte): string
    {
        $dossier = self::dossierVersions($cheminAbsoluDocument);
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException("version : impossible de créer $dossier");
        }
        self::cacher($dossier);
        $extension = pathinfo($cheminAbsoluDocument, PATHINFO_EXTENSION);
        $archive = sprintf('%s/v%03d_%s%s', $dossier, $numero, substr($empreinte, 0, 8),
            $extension !== '' ? ".$extension" : '');
        if (!copy($cheminAbsoluDocument, $archive)) {
            throw new \RuntimeException("version : copie impossible vers $archive");
        }

        return $archive;
    }

    /** Attribut caché « façon mac » sous Windows : `.versions/` disparaît de l’Explorateur sans gêner l'application. */
    public static function cacher(string $cheminAbsolu): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('attrib +H ' . escapeshellarg($cheminAbsolu) . ' 2>NUL');
        }
    }

    /**
     * Déplace un document ET ses annexes (`.meta.json`, dossier `.versions/`), d'un chemin vers un autre.
     * Rend le chemin final : le nom est dédoublonné si la cible est déjà occupée.
     */
    public static function deplacerAvecAnnexes(string $avant, string $apres): string
    {
        $dossierApres = dirname($apres);
        if (!is_dir($dossierApres) && !mkdir($dossierApres, 0775, true) && !is_dir($dossierApres)) {
            throw new \RuntimeException("déplacement : impossible de créer $dossierApres");
        }
        $apres = $dossierApres . '/' . self::nomDisponible($dossierApres, basename($apres));
        if (!@rename($avant, $apres)) {
            throw new \RuntimeException("déplacement : impossible de déplacer $avant");
        }
        if (is_file("$avant.meta.json")) {
            @rename("$avant.meta.json", "$apres.meta.json");
        }
        $versionsAvant = self::dossierVersions($avant);
        if (is_dir($versionsAvant)) {
            $versionsApres = self::dossierVersions($apres);
            if (!is_dir(dirname($versionsApres)) && !mkdir(dirname($versionsApres), 0775, true)) {
                throw new \RuntimeException('déplacement : impossible de créer le dossier .versions');
            }
            @rename($versionsAvant, $versionsApres);
        }

        return $apres;
    }

    /** Copie un document vers le fonds (le source n'est jamais supprimé par le dépôt) puis ses annexes. */
    public static function copierAvecMeta(string $source, string $cible, string $contenuMeta): string
    {
        $dossier = dirname($cible);
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException("dépôt : impossible de créer $dossier");
        }
        $cible = $dossier . '/' . self::nomDisponible($dossier, basename($cible));
        if (!copy($source, $cible)) {
            throw new \RuntimeException("dépôt : copie impossible de $source");
        }
        file_put_contents("$cible.meta.json", $contenuMeta);

        return $cible;
    }
}
