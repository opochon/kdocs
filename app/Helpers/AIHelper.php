<?php
/**
 * K-Docs - AI Helper Functions
 * Utility functions for AI operations (extracted from POC)
 */

namespace KDocs\Helpers;

class AIHelper
{
    /**
     * Parse AI response containing JSON (handles markdown code blocks, etc.)
     */
    public static function parseJsonResponse(string $text): ?array
    {
        $text = trim($text);

        // Extract JSON if wrapped in markdown code blocks
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches)) {
            $text = trim($matches[1]);
        }

        // Try direct parse
        $data = @json_decode($text, true);
        if ($data !== null) {
            return $data;
        }

        // Find JSON object in text
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $data = @json_decode($matches[0], true);
            if ($data !== null) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Ensure UTF-8 encoding (fix Windows encoding issues)
     */
    public static function ensureUtf8(string $text): string
    {
        if (empty($text)) return '';

        // Detect encoding
        $encoding = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252', 'ASCII'], true);

        // Convert if needed
        if ($encoding && $encoding !== 'UTF-8') {
            $text = mb_convert_encoding($text, 'UTF-8', $encoding);
        }

        // Force conversion from Windows-1252 if still invalid
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        // Clean control characters (keep newlines and tabs)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        return $text;
    }

    /**
     * Cosine similarity between two vectors
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        return ($normA > 0 && $normB > 0) ? $dot / ($normA * $normB) : 0.0;
    }

    /**
     * Build classification prompt
     */
    public static function buildClassificationPrompt(string $text, array $categories = []): string
    {
        $text = mb_substr($text, 0, 4000);

        $categoriesList = !empty($categories)
            ? implode(', ', $categories)
            : 'Facture, Contrat, Courrier, Rapport, Formulaire, Autre';

        return <<<PROMPT
Analyse ce document et propose une classification.

TEXTE:
{$text}

CATEGORIES DISPONIBLES: {$categoriesList}

Réponds UNIQUEMENT en JSON valide:
{
  "type": "<catégorie>",
  "confidence": <0.0-1.0>,
  "summary": "<résumé 1-2 phrases>",
  "fields": {
    "date": "<YYYY-MM-DD ou null>",
    "amount": <decimal ou null>,
    "reference": "<référence ou null>",
    "correspondent": "<nom expéditeur/destinataire ou null>"
  }
}
PROMPT;
    }

    /**
     * Extract structured fields from text using patterns
     */
    public static function extractFields(string $text): array
    {
        $fields = [
            'date' => null,
            'amount' => null,
            'reference' => null,
            'iban' => null,
            'correspondent' => null,
        ];

        // Date patterns
        $datePatterns = [
            '/(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{4})/',  // DD/MM/YYYY
            '/(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{2})(?!\d)/',  // DD/MM/YY
            '/(\d{4})-(\d{2})-(\d{2})/',  // YYYY-MM-DD
        ];

        foreach ($datePatterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                if (strlen($m[3]) === 2) {
                    $year = (int)$m[3];
                    $year = $year <= 30 ? 2000 + $year : 1900 + $year;
                    $fields['date'] = sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]);
                } elseif (strlen($m[1]) === 4) {
                    $fields['date'] = sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
                } else {
                    $fields['date'] = sprintf('%s-%02d-%02d', $m[3], $m[2], $m[1]);
                }
                break;
            }
        }

        // Amount patterns
        if (preg_match('/(?:CHF|EUR|USD|€|\$)\s*([0-9\'\s]+[.,]\d{2})/i', $text, $m)) {
            $fields['amount'] = (float)str_replace([' ', "'", ','], ['', '', '.'], $m[1]);
        } elseif (preg_match('/([0-9\'\s]+[.,]\d{2})\s*(?:CHF|EUR|USD|€|\$|francs?)/i', $text, $m)) {
            $fields['amount'] = (float)str_replace([' ', "'", ','], ['', '', '.'], $m[1]);
        }

        // IBAN
        if (preg_match('/[A-Z]{2}\d{2}[\s]?(?:\d{4}[\s]?){4,7}\d{1,4}/i', $text, $m)) {
            $fields['iban'] = preg_replace('/\s/', '', $m[0]);
        }

        // Reference patterns
        if (preg_match('/(?:R[ée]f[ée]rence|N[°o]|Facture|Invoice)[:\s]*([A-Z0-9\-\/]+)/i', $text, $m)) {
            $fields['reference'] = $m[1];
        }

        return $fields;
    }
}
