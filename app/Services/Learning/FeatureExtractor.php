<?php
/**
 * K-Docs - Feature Extractor
 * Extrait les caractéristiques d'un document pour le ML
 */

namespace KDocs\Services\Learning;

use KDocs\Core\Database;

class FeatureExtractor
{
    /**
     * Mots vides à ignorer (stopwords français)
     */
    private const STOPWORDS = [
        'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'et', 'en', 'au', 'aux',
        'ce', 'cette', 'ces', 'son', 'sa', 'ses', 'leur', 'leurs', 'mon', 'ma', 'mes',
        'ton', 'ta', 'tes', 'notre', 'nos', 'votre', 'vos', 'qui', 'que', 'quoi',
        'dont', 'où', 'pour', 'par', 'sur', 'sous', 'avec', 'sans', 'dans', 'entre',
        'vers', 'chez', 'il', 'elle', 'on', 'nous', 'vous', 'ils', 'elles', 'je', 'tu',
        'est', 'sont', 'être', 'avoir', 'fait', 'faire', 'dit', 'dire', 'peut', 'pouvoir',
        'tout', 'tous', 'toute', 'toutes', 'autre', 'autres', 'même', 'aussi', 'plus',
        'moins', 'très', 'bien', 'mal', 'peu', 'trop', 'comme', 'mais', 'ou', 'donc',
        'car', 'ni', 'si', 'non', 'oui', 'pas', 'ne', 'se', 'lui', 'y', 'ci', 'là',
        'ici', 'cela', 'ceci', 'celui', 'celle', 'ceux', 'celles', 'quelque', 'chaque',
        'quel', 'quelle', 'quels', 'quelles', 'ainsi', 'alors', 'après', 'avant',
        'encore', 'déjà', 'toujours', 'jamais', 'souvent', 'parfois', 'depuis', 'jusqu',
        'the', 'a', 'an', 'and', 'or', 'but', 'is', 'are', 'was', 'were', 'be', 'been',
        'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
        'should', 'may', 'might', 'must', 'shall', 'can', 'need', 'to', 'of', 'in',
        'for', 'on', 'with', 'at', 'by', 'from', 'as', 'into', 'through', 'during',
        'before', 'after', 'above', 'below', 'between', 'under', 'again', 'further',
        'then', 'once', 'here', 'there', 'when', 'where', 'why', 'how', 'all', 'each',
        'few', 'more', 'most', 'other', 'some', 'such', 'no', 'not', 'only', 'own',
        'same', 'so', 'than', 'too', 'very', 'just', 'this', 'that', 'these', 'those'
    ];

    /**
     * Buckets pour les montants
     */
    private const AMOUNT_BUCKETS = [
        0 => '0-100',
        100 => '100-500',
        500 => '500-1k',
        1000 => '1k-5k',
        5000 => '5k-10k',
        10000 => '10k+'
    ];

    /**
     * Extrait toutes les features d'un document
     */
    public function extract(int $documentId): array
    {
        $db = Database::getInstance();

        // Charger le document
        $stmt = $db->prepare("
            SELECT d.*,
                   dt.label as document_type_label,
                   c.name as correspondent_name
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.id = ?
        ");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$document) {
            return [];
        }

        return $this->extractFromData($document);
    }

    /**
     * Extrait les features depuis les données du document
     */
    public function extractFromData(array $document): array
    {
        $features = [
            'correspondent_id' => $document['correspondent_id'] ?? null,
            'document_type_id' => $document['document_type_id'] ?? null,
            'amount_range' => $this->getAmountRange($document['amount'] ?? null),
            'keywords' => $this->extractKeywords($document['ocr_content'] ?? $document['content'] ?? ''),
            'content_hash' => $this->hashContent($document['ocr_content'] ?? $document['content'] ?? ''),
            'has_amount' => !empty($document['amount']),
            'has_date' => !empty($document['doc_date']),
            'file_type' => $this->getFileType($document['mime_type'] ?? $document['filename'] ?? ''),
            'tag_ids' => $this->getDocumentTags($document['id'] ?? null),
            'title_keywords' => $this->extractKeywords($document['title'] ?? '', 10),
            'correspondent_name' => $document['correspondent_name'] ?? null,
            'document_type_label' => $document['document_type_label'] ?? null
        ];

        return $features;
    }

    /**
     * Détermine le bucket de montant
     */
    private function getAmountRange(?float $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        $amount = abs($amount);

        foreach (array_reverse(self::AMOUNT_BUCKETS, true) as $threshold => $label) {
            if ($amount >= $threshold) {
                return $label;
            }
        }

        return '0-100';
    }

    /**
     * Extrait les mots-clés d'un texte
     */
    public function extractKeywords(string $text, int $limit = 50): array
    {
        if (empty($text)) {
            return [];
        }

        // Normaliser le texte
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        // Extraire les mots
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Filtrer les stopwords et les mots trop courts
        $words = array_filter($words, function ($word) {
            return strlen($word) >= 3 && !in_array($word, self::STOPWORDS);
        });

        // Compter les occurrences
        $wordCounts = array_count_values($words);

        // Trier par fréquence
        arsort($wordCounts);

        // Prendre les top N
        return array_slice(array_keys($wordCounts), 0, $limit);
    }

    /**
     * Génère un hash du contenu normalisé
     */
    private function hashContent(string $content): string
    {
        // Normaliser
        $normalized = mb_strtolower(trim($content), 'UTF-8');
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);

        return hash('sha256', $normalized);
    }

    /**
     * Détermine le type de fichier
     */
    private function getFileType(string $mimeOrFilename): string
    {
        $mimeMap = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'image',
            'image/jpg' => 'image',
            'image/png' => 'image',
            'image/gif' => 'image',
            'image/tiff' => 'image',
            'application/msword' => 'word',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word',
            'application/vnd.ms-excel' => 'excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'excel',
            'text/plain' => 'text'
        ];

        // D'abord essayer comme MIME
        if (isset($mimeMap[$mimeOrFilename])) {
            return $mimeMap[$mimeOrFilename];
        }

        // Sinon, extraire l'extension
        $ext = strtolower(pathinfo($mimeOrFilename, PATHINFO_EXTENSION));
        $extMap = [
            'pdf' => 'pdf',
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'tiff' => 'image',
            'doc' => 'word', 'docx' => 'word',
            'xls' => 'excel', 'xlsx' => 'excel',
            'txt' => 'text'
        ];

        return $extMap[$ext] ?? 'other';
    }

    /**
     * Récupère les IDs des tags d'un document
     */
    private function getDocumentTags(?int $documentId): array
    {
        if (!$documentId) {
            return [];
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT tag_id FROM document_tags WHERE document_id = ?");
        $stmt->execute([$documentId]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'tag_id');
    }

    /**
     * Compare deux ensembles de features et retourne un score de similarité
     */
    public function calculateSimilarity(array $features1, array $features2): float
    {
        $score = 0.0;
        $weights = [
            'correspondent_id' => 0.30,
            'document_type_id' => 0.25,
            'amount_range' => 0.15,
            'keywords' => 0.15,
            'tag_ids' => 0.10,
            'file_type' => 0.05
        ];

        // Correspondant identique
        if (!empty($features1['correspondent_id']) && $features1['correspondent_id'] === $features2['correspondent_id']) {
            $score += $weights['correspondent_id'];
        }

        // Type de document identique
        if (!empty($features1['document_type_id']) && $features1['document_type_id'] === $features2['document_type_id']) {
            $score += $weights['document_type_id'];
        }

        // Même bucket de montant
        if (!empty($features1['amount_range']) && $features1['amount_range'] === $features2['amount_range']) {
            $score += $weights['amount_range'];
        }

        // Overlap des mots-clés (Jaccard)
        $keywords1 = $features1['keywords'] ?? [];
        $keywords2 = $features2['keywords'] ?? [];

        if (!empty($keywords1) && !empty($keywords2)) {
            $intersection = count(array_intersect($keywords1, $keywords2));
            $union = count(array_unique(array_merge($keywords1, $keywords2)));

            if ($union > 0) {
                $keywordSimilarity = $intersection / $union;
                $score += $weights['keywords'] * $keywordSimilarity;
            }
        }

        // Overlap des tags
        $tags1 = $features1['tag_ids'] ?? [];
        $tags2 = $features2['tag_ids'] ?? [];

        if (!empty($tags1) && !empty($tags2)) {
            $intersection = count(array_intersect($tags1, $tags2));
            $union = count(array_unique(array_merge($tags1, $tags2)));

            if ($union > 0) {
                $tagSimilarity = $intersection / $union;
                $score += $weights['tag_ids'] * $tagSimilarity;
            }
        }

        // Même type de fichier
        if (!empty($features1['file_type']) && $features1['file_type'] === $features2['file_type']) {
            $score += $weights['file_type'];
        }

        return min(1.0, $score);
    }

    /**
     * Extrait les features pour un nouveau document (avant insertion)
     */
    public function extractFromUpload(string $content, ?string $filename = null, ?string $mimeType = null): array
    {
        return [
            'correspondent_id' => null,
            'document_type_id' => null,
            'amount_range' => null,
            'keywords' => $this->extractKeywords($content),
            'content_hash' => $this->hashContent($content),
            'has_amount' => false,
            'has_date' => false,
            'file_type' => $this->getFileType($mimeType ?? $filename ?? ''),
            'tag_ids' => [],
            'title_keywords' => $filename ? $this->extractKeywords(pathinfo($filename, PATHINFO_FILENAME), 10) : []
        ];
    }
}
