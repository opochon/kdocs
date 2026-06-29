<?php

declare(strict_types=1);

namespace KDocs\Services;

/**
 * Métadonnées déduites du chemin / nom de fichier (sans IA).
 * Utilisé à l'indexation sur arborescence existante (ex. dossier 2024/).
 */
class DocumentMetadataHeuristics
{
    public static function suggestFromPath(string $relativePath, string $filename): array
    {
        $path = trim(str_replace('\\', '/', $relativePath), '/');
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $haystack = $path !== '' ? $path . '/' . $basename : $basename;

        $date = self::extractDate($haystack);
        $title = self::cleanTitle($basename);

        return [
            'document_date' => $date,
            'title' => $title,
        ];
    }

    public static function extractDate(string $text): ?string
    {
        // ISO 2024-06-29 ou 2024_06_29 — gardes (?<!\d)/(?!\d) car \b considère '_' comme
        // caractère de mot (scan_2024-06-29_final serait vu comme un seul token).
        if (preg_match('#(?<!\d)(20\d{2})[-_/.](\d{1,2})[-_/.](\d{1,2})(?!\d)#', $text, $m)) {
            return self::validDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // FR 29.06.2024 ou 29-06-2024
        if (preg_match('#(?<!\d)(\d{1,2})[.\-/](\d{1,2})[.\-/](20\d{2})(?!\d)#', $text, $m)) {
            return self::validDate((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        // Segment dossier année seul : 2024/...
        if (preg_match('#(?:^|/)(20\d{2})(?:/|$)#', $text, $m)) {
            return sprintf('%04d-01-01', (int) $m[1]);
        }

        // Année dans le nom : rapport_2024_final
        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            return sprintf('%04d-01-01', (int) $m[1]);
        }

        return null;
    }

    private static function validDate(int $year, int $month, int $day): ?string
    {
        if ($year < 1990 || $year > 2100) {
            return null;
        }
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function cleanTitle(string $basename): string
    {
        $title = preg_replace('/[-_]+/', ' ', $basename) ?? $basename;
        $title = trim($title);
        if (strtolower($title) === 'toclassify') {
            return 'Document sans titre';
        }

        return $title !== '' ? $title : 'Document sans titre';
    }
}
