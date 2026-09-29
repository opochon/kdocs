<?php

declare(strict_types=1);

namespace Ged\Recherche;

/**
 * Parcourt le fonds documentaire et rend chaque document avec son texte. Le `.meta.json` porte le texte lu
 * par l'ingestion (`texte`, `ocr`) ; pour un fichier texte sans OCR, le contenu du fichier est indexé.
 * Aucun index parallèle : si la base est vidée, cette source suffit à tout reconstruire.
 */
final class SourceRecherche
{
    /** Extensions dont le contenu EST le texte : inutile d'attendre un OCR. */
    private const TEXTES = ['txt', 'md', 'csv', 'json', 'log'];

    public function __construct(private readonly string $racine)
    {
    }

    /** @return list<DocumentFonds> ordonnés par chemin */
    public function documents(): array
    {
        $documents = [];
        foreach ($this->metaFichiers() as $metaFichier) {
            $document = $this->lire($metaFichier);
            if ($document !== null) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /** @return list<string> chemins absolus des `.meta.json`, dossiers cachés exclus */
    private function metaFichiers(): array
    {
        if (!is_dir($this->racine)) {
            return [];
        }
        $fichiers = [];
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->racine, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
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

    private function lire(string $metaFichier): ?DocumentFonds
    {
        $meta = json_decode((string) file_get_contents($metaFichier), true);
        if (!is_array($meta)) {
            return null;
        }
        $fichier = (string) ($meta['fichier'] ?? substr(basename($metaFichier), 0, -strlen('.meta.json')));
        if ($fichier === '' || str_contains($fichier, '..') || str_contains($fichier, '/') || str_contains($fichier, '\\')) {
            return null;
        }
        $absolu = dirname($metaFichier) . DIRECTORY_SEPARATOR . $fichier;
        if (!is_file($absolu)) {
            return null;
        }
        $racine = rtrim(str_replace('\\', '/', $this->racine), '/');
        $chemin = ltrim(substr(str_replace('\\', '/', $absolu), strlen($racine)), '/');
        $extension = strtolower(pathinfo($fichier, PATHINFO_EXTENSION));
        $id = isset($meta['id']) && is_numeric($meta['id']) ? (int) $meta['id'] : null;

        return new DocumentFonds(
            $chemin,
            $id,
            (string) ($meta['titre'] ?? $fichier),
            self::chaine($meta['type'] ?? null),
            self::chaine($meta['emetteur'] ?? null),
            self::date($meta['date'] ?? null),
            self::montant($meta['montant'] ?? null),
            $this->texte($meta, $absolu, $extension),
            hash('sha256', filesize($metaFichier) . '|' . filemtime($metaFichier)),
        );
    }

    /** @param array<string, mixed> $meta */
    private function texte(array $meta, string $absolu, string $extension): string
    {
        foreach (['texte', 'ocr'] as $cle) {
            if (isset($meta[$cle]) && is_string($meta[$cle]) && trim($meta[$cle]) !== '') {
                return (string) $meta[$cle];
            }
        }
        if (in_array($extension, self::TEXTES, true)) {
            return (string) @file_get_contents($absolu);
        }

        return '';
    }

    private static function chaine(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }

    private static function date(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return preg_match('#^\d{4}-\d{2}-\d{2}$#', $valeur) === 1 ? $valeur : null;
    }

    private static function montant(mixed $valeur): ?float
    {
        return is_numeric($valeur) ? (float) $valeur : null;
    }
}
