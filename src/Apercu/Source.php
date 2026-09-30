<?php

declare(strict_types=1);

namespace Ged\Apercu;

use Ged\Base;
use Ged\Config;
use PDO;
use Throwable;

/**
 * Source des pièces : un dossier documentaire parcouru à la demande, chaque pièce retrouvée par le `id`
 * de son `.meta.json`. L'index documentaire du Socle est interrogé d'abord (O(1)) ; le parcours du dossier
 * n'est qu'un REPLI quand l'index ne connaît pas la pièce (base absente, fonds reconstruit à la main).
 *
 * La racine vient de `STORAGE_ROOT` ; tant que le secteur Socle n'a pas posé le vrai dossier, la valeur
 * par défaut pointe sur le corpus synthétique versionné, ce qui rend l'aperçu démontrable et testable.
 */
final class Source
{
    private const IMAGES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'avif', 'tif', 'tiff'];
    private const BUREAUTIQUE = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'csv'];
    private const MESSAGES = ['eml'];

    public function __construct(private readonly string $racine, private readonly ?PDO $pdo = null)
    {
    }

    public static function depuis(Config $config): self
    {
        $pdo = null;
        try {
            $pdo = Base::pdo($config);
        } catch (Throwable) {
            // Base absente ou muette : le dossier fait foi, la résolution retombe sur le parcours.
        }

        return new self($config->chemin('STORAGE_ROOT', 'tests/corpus/apercu/documents'), $pdo);
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
        if (in_array($extension, self::MESSAGES, true)) {
            return 'message';
        }

        return 'inconnu';
    }

    public function parId(int $id): ?Piece
    {
        // L'index résout l'id en O(1) ; le parcours du dossier n'est qu'un REPLI quand l'index ne
        // connaît pas la pièce (base absente, fonds reconstruit à la main). Le dossier fait toujours foi.
        $piece = $this->parIdParIndex($id);
        if ($piece !== null) {
            return $piece;
        }
        foreach ($this->toutes() as $piece) {
            if ($piece->id === $id) {
                return $piece;
            }
        }

        return null;
    }

    /** Résolution par l'index documentaire (`documents.id` → `chemin_relatif`), sans balayer le fonds. */
    private function parIdParIndex(int $id): ?Piece
    {
        if ($this->pdo === null) {
            return null;
        }
        try {
            $requete = $this->pdo->prepare('SELECT chemin_relatif FROM documents'
                . ' WHERE id = ? AND supprime_le IS NULL LIMIT 1');
            $requete->execute([$id]);
            $relatif = $requete->fetchColumn();
        } catch (Throwable) {
            return null;
        }
        if (!is_string($relatif) || $relatif === '' || str_starts_with($relatif, '/') || str_contains($relatif, '..')) {
            return null;
        }
        $racine = rtrim(str_replace('\\', '/', $this->racine), '/');
        $absolu = $racine . '/' . str_replace('\\', '/', $relatif);
        $metaFichier = $absolu . '.meta.json';
        if (!is_file($absolu) || !is_file($metaFichier)) {
            return null;
        }
        $piece = $this->lireMeta($metaFichier, $id);

        return $piece !== null && $piece->id === $id ? $piece : null;
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

    /**
     * Lit une fiche. L'`id` vient du `.meta.json` ; à défaut (document déposé par l'application, dont
     * l'identifiant vit dans l'index) il est fourni par l'appelant qui l'a résolu.
     */
    private function lireMeta(string $metaFichier, ?int $idIndex = null): ?Piece
    {
        $donnees = json_decode((string) file_get_contents($metaFichier), true);
        $id = $donnees['id'] ?? $idIndex;
        if (!is_array($donnees) || !is_numeric($id)) {
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
        if (!in_array($categorie, ['pdf', 'image', 'bureautique', 'message', 'inconnu'], true)) {
            $categorie = self::categorie($extension);
        }

        return new Piece(
            (int) $id,
            realpath($chemin) ?: $chemin,
            (string) ($donnees['titre'] ?? $fichier),
            $categorie,
            $extension,
            max(1, (int) ($donnees['pages'] ?? 1)),
        );
    }
}
