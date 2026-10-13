<?php

declare(strict_types=1);

namespace Ged\DepotFileserver;

/**
 * Gestes d'arborescence du DEPOT FILESERVER (famille « archivage / versions »,
 * docs/ARCHIVAGE-FILESERVER.md) : parcours portable Windows/Linux, empreintes sha256,
 * dossiers internes, nommage du dossier de versions masqué.
 *
 * Le dossier fait foi : ce secteur ne lit et n'écrit QUE des fichiers, jamais la base.
 */
final class Arborescence
{
    /** Dossier interne du secteur : le manifeste d'empreintes et le journal vivent ici. */
    public const INTERNE = '.depot-fileserver';

    /** Fichiers d'appoint des systèmes de fichiers : jamais des documents, quelle que soit la plateforme. */
    private const POUBELLE_OS = ['thumbs.db', 'desktop.ini', '.ds_store'];

    /** Empreinte sha256 d'un fichier (même algorithme que le reste de la GED). */
    public static function empreinte(string $absolu): string
    {
        $h = hash_file('sha256', $absolu);
        if ($h === false) {
            throw new \RuntimeException('empreinte : impossible de lire ' . $absolu);
        }

        return $h;
    }

    /**
     * Un nom est INTERNE au dépôt (dossier masqué, annexe d'OS, temporaire d'édition) : il n'est
     * jamais un document du dépôt, et ses copies de versions ne sont jamais re-parcourues.
     */
    public static function estInterne(string $nom): bool
    {
        return str_starts_with($nom, '.')
            || in_array(strtolower($nom), self::POUBELLE_OS, true)
            || str_starts_with($nom, '~$')
            || str_ends_with(strtolower($nom), '.tmp')
            || str_ends_with(strtolower($nom), '.part');
    }

    /**
     * Parcourt TOUS les fichiers du dépôt (relatif => [absolu, taille]), en excluant les dossiers
     * internes. Les clés sont normalisées en `/` : l'index est le même sous Windows et sous Linux.
     *
     * @return array<string, array{absolu:string, taille:int}>
     */
    public static function parcourir(string $racine): array
    {
        $racine = rtrim(str_replace('\\', '/', $racine), '/');
        if (!is_dir($racine)) {
            throw new \RuntimeException('dépôt absent : ' . $racine);
        }
        $filtre = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f): bool => !self::estInterne($f->getFilename()),
        );
        $trouves = [];
        $iterateur = new \RecursiveIteratorIterator($filtre);
        foreach ($iterateur as $fichier) {
            /** @var \SplFileInfo $fichier */
            if (!$fichier->isFile()) {
                continue;
            }
            $absolu = str_replace('\\', '/', $fichier->getPathname());
            $relatif = substr($absolu, strlen($racine) + 1);
            $trouves[$relatif] = ['absolu' => $absolu, 'taille' => (int) $fichier->getSize()];
        }
        ksort($trouves);

        return $trouves;
    }

    /** Dossier de versions masqué `.<nom du fichier>` à côté du fichier (docs/ARCHIVAGE-FILESERVER.md). */
    public static function dossierVersions(string $absoluDocument): string
    {
        return dirname($absoluDocument) . '/.' . basename($absoluDocument);
    }

    /** Attribut caché sous Windows (best effort, jamais bloquant) : le dossier disparaît de l'Explorateur. */
    public static function cacher(string $cheminAbsolu): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('attrib +H ' . escapeshellarg($cheminAbsolu) . ' 2>NUL');
        }
    }

    public static function assurerDossier(string $dossier): void
    {
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('dossier impossible : ' . $dossier);
        }
    }

    /**
     * JSON identique à chaque encodage (tri des clés) : le manifeste et le journal sont stables,
     * donc comparables d'un passage à l'autre, quel que soit l'ordre d'itération du système.
     *
     * @param array<mixed> $valeurs
     */
    public static function json(array $valeurs, bool $lisible = false): string
    {
        $valeurs = self::trierRecursif($valeurs);

        return json_encode(
            $valeurs,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($lisible ? JSON_PRETTY_PRINT : 0),
        ) ?: '{}';
    }

    /**
     * @param array<mixed> $valeurs
     * @return array<mixed>
     */
    private static function trierRecursif(array $valeurs): array
    {
        if (array_is_list($valeurs)) {
            return array_map(static fn ($v) => is_array($v) ? self::trierRecursif($v) : $v, $valeurs);
        }
        ksort($valeurs);
        foreach ($valeurs as $cle => $valeur) {
            if (is_array($valeur)) {
                $valeurs[$cle] = self::trierRecursif($valeur);
            }
        }

        return $valeurs;
    }
}
