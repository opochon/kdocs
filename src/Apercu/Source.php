<?php

declare(strict_types=1);

namespace Ged\Apercu;

use Ged\Config;

/**
 * Source des pièces : un dossier documentaire parcouru à la demande, chaque pièce retrouvée par le `id`
 * de son `.meta.json`. Aucun index parallèle : supprimer la base ne change rien à ce que l'on peut voir.
 *
 * La racine vient de `STORAGE_ROOT` ; tant que le secteur Socle n'a pas posé le vrai dossier, la valeur
 * par défaut pointe sur le corpus synthétique versionné, ce qui rend l'aperçu démontrable et testable.
 */
final class Source
{
    private const IMAGES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'avif', 'tif', 'tiff'];
    private const BUREAUTIQUE = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'csv'];

    public function __construct(private readonly string $racine)
    {
    }

    public static function depuis(Config $config): self
    {
        return new self($config->chemin('STORAGE_ROOT', 'tests/corpus/apercu/documents'));
    }

    public function racine(): string
    {
        return $this->racine;
    }

    public static function categorie(string $extension): string
    {
        $extension = strtolower($extension);
        if ($extension === 'pdf') {
            return 'pdf';
        }
        if (in_array($extension, self::IMAGES, true)) {
            return 'image';
        }
        if (in_array($extension, self::BUREAUTIQUE, true)) {
            return 'bureautique';
        }

        return 'inconnu';
    }

    public function parId(int $id): ?Piece
    {
        foreach ($this->toutes() as $piece) {
            if ($piece->id === $id) {
                return $piece;
            }
        }

        return null;
    }

    /** @return list<Piece> */
    public function toutes(): array
    {
        $pieces = [];
        foreach ($this->metaFichiers() as $meta) {
            $piece = $this->lireMeta($meta);
            if ($piece !== null) {
                $pieces[$piece->id] = $piece;
            }
        }
        ksort($pieces);

        return array_values($pieces);
    }

    /** @return list<string> */
    private function metaFichiers(): array
    {
        if (!is_dir($this->racine)) {
            return [];
        }
        $fichiers = [];
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->racine, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
                // Les caches d'aperçu vivent dans des dossiers cachés à côté des documents : on ne les indexe pas.
                static fn (string $chemin): bool => !str_starts_with(basename($chemin), '.')
            )
        );
        foreach ($iterateur as $chemin) {
            if (str_ends_with($chemin, '.meta.json')) {
                $fichiers[] = $chemin;
            }
        }
        sort($fichiers);

        return $fichiers;
    }

    private function lireMeta(string $metaFichier): ?Piece
    {
        $donnees = json_decode((string) file_get_contents($metaFichier), true);
        if (!is_array($donnees) || !isset($donnees['id']) || !is_numeric($donnees['id'])) {
            return null;
        }
        $fichier = (string) ($donnees['fichier'] ?? substr(basename($metaFichier), 0, -strlen('.meta.json')));
        // Un `.meta.json` ne peut jamais désigner un fichier hors de la racine documentaire.
        if ($fichier === '' || str_contains($fichier, '..') || str_contains($fichier, '/') || str_contains($fichier, '\\')) {
            return null;
        }
        $chemin = dirname($metaFichier) . DIRECTORY_SEPARATOR . $fichier;
        if (!is_file($chemin)) {
            return null;
        }
        $extension = strtolower(pathinfo($fichier, PATHINFO_EXTENSION));
        $categorie = (string) ($donnees['categorie'] ?? self::categorie($extension));
        if (!in_array($categorie, ['pdf', 'image', 'bureautique', 'inconnu'], true)) {
            $categorie = self::categorie($extension);
        }

        return new Piece(
            (int) $donnees['id'],
            realpath($chemin) ?: $chemin,
            (string) ($donnees['titre'] ?? $fichier),
            $categorie,
            $extension,
            max(1, (int) ($donnees['pages'] ?? 1)),
        );
    }
}
