<?php
/**
 * K-Docs - Similarity Matcher
 * Trouve les documents similaires pour les suggestions ML
 */

namespace KDocs\Services\Learning;

use KDocs\Core\Database;
use KDocs\Models\ClassificationTrainingData;

class SimilarityMatcher
{
    private FeatureExtractor $featureExtractor;

    public function __construct()
    {
        $this->featureExtractor = new FeatureExtractor();
    }

    /**
     * Trouve les documents similaires pour un champ donné
     *
     * @param int $documentId Document cible
     * @param string $fieldCode Champ à prédire
     * @param int $limit Nombre max de résultats
     * @return array Documents similaires avec scores
     */
    public function findSimilar(int $documentId, string $fieldCode, int $limit = 10): array
    {
        // Extraire les features du document cible
        $targetFeatures = $this->featureExtractor->extract($documentId);

        if (empty($targetFeatures)) {
            return [];
        }

        // Récupérer les données d'entraînement pour ce champ
        $trainingData = ClassificationTrainingData::getForField($fieldCode, 500);

        if (empty($trainingData)) {
            return [];
        }

        // Calculer les similarités
        $similarities = [];

        foreach ($trainingData as $training) {
            // Ne pas comparer avec soi-même
            if ($training['document_id'] == $documentId) {
                continue;
            }

            $trainingFeatures = json_decode($training['features'], true);
            if (!$trainingFeatures) {
                continue;
            }

            $similarity = $this->featureExtractor->calculateSimilarity($targetFeatures, $trainingFeatures);

            // Pondérer par la confiance de la source
            $weightedSimilarity = $similarity * ($training['confidence'] ?? 1.0);

            if ($weightedSimilarity > 0.1) { // Seuil minimum
                $similarities[] = [
                    'training_id' => $training['id'],
                    'document_id' => $training['document_id'],
                    'document_title' => $training['document_title'],
                    'field_value' => $training['field_value'],
                    'similarity' => $similarity,
                    'weighted_similarity' => $weightedSimilarity,
                    'source' => $training['source'],
                    'source_confidence' => $training['confidence']
                ];
            }
        }

        // Trier par similarité pondérée
        usort($similarities, fn($a, $b) => $b['weighted_similarity'] <=> $a['weighted_similarity']);

        return array_slice($similarities, 0, $limit);
    }

    /**
     * Prédit une valeur pour un champ basé sur les documents similaires
     *
     * @param int $documentId Document cible
     * @param string $fieldCode Champ à prédire
     * @return array|null Prédiction avec confiance
     */
    public function predict(int $documentId, string $fieldCode): ?array
    {
        $similar = $this->findSimilar($documentId, $fieldCode, 10);

        if (empty($similar)) {
            return null;
        }

        // Vote pondéré
        $votes = [];

        foreach ($similar as $match) {
            $value = $match['field_value'];
            $weight = $match['weighted_similarity'];

            if (!isset($votes[$value])) {
                $votes[$value] = [
                    'value' => $value,
                    'total_weight' => 0,
                    'count' => 0,
                    'documents' => []
                ];
            }

            $votes[$value]['total_weight'] += $weight;
            $votes[$value]['count']++;
            $votes[$value]['documents'][] = [
                'id' => $match['document_id'],
                'title' => $match['document_title'],
                'similarity' => $match['similarity']
            ];
        }

        // Trouver le gagnant
        $totalWeight = array_sum(array_column($votes, 'total_weight'));

        if ($totalWeight == 0) {
            return null;
        }

        // Trier par poids total
        uasort($votes, fn($a, $b) => $b['total_weight'] <=> $a['total_weight']);

        $winner = reset($votes);
        $confidence = $winner['total_weight'] / $totalWeight;

        // Ajuster la confiance en fonction du nombre de votes
        $countBonus = min(0.15, $winner['count'] * 0.03);
        $confidence = min(1.0, $confidence + $countBonus);

        return [
            'field_code' => $fieldCode,
            'predicted_value' => $winner['value'],
            'confidence' => round($confidence, 2),
            'vote_count' => $winner['count'],
            'total_similar' => count($similar),
            'similar_documents' => array_slice($winner['documents'], 0, 5),
            'all_candidates' => array_map(fn($v) => [
                'value' => $v['value'],
                'weight' => round($v['total_weight'] / $totalWeight, 2),
                'count' => $v['count']
            ], array_slice(array_values($votes), 0, 3))
        ];
    }

    /**
     * Prédit plusieurs champs à la fois
     */
    public function predictMultiple(int $documentId, array $fieldCodes): array
    {
        $predictions = [];

        foreach ($fieldCodes as $fieldCode) {
            $prediction = $this->predict($documentId, $fieldCode);
            if ($prediction) {
                $predictions[$fieldCode] = $prediction;
            }
        }

        return $predictions;
    }

    /**
     * Trouve des documents similaires globalement (pas par champ)
     */
    public function findGloballySimilar(int $documentId, int $limit = 20): array
    {
        $targetFeatures = $this->featureExtractor->extract($documentId);

        if (empty($targetFeatures)) {
            return [];
        }

        $db = Database::getInstance();

        // Récupérer les documents récemment classifiés
        $stmt = $db->prepare("
            SELECT d.id, d.title, d.correspondent_id, d.document_type_id,
                   d.amount, d.doc_date, d.mime_type, d.ocr_content,
                   dt.label as document_type_label,
                   c.name as correspondent_name
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.id != ?
              AND d.last_classified_at IS NOT NULL
            ORDER BY d.last_classified_at DESC
            LIMIT 200
        ");
        $stmt->execute([$documentId]);
        $candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $similarities = [];

        foreach ($candidates as $candidate) {
            $candidateFeatures = $this->featureExtractor->extractFromData($candidate);
            $similarity = $this->featureExtractor->calculateSimilarity($targetFeatures, $candidateFeatures);

            if ($similarity > 0.2) {
                $similarities[] = [
                    'document_id' => $candidate['id'],
                    'title' => $candidate['title'],
                    'similarity' => $similarity,
                    'correspondent' => $candidate['correspondent_name'],
                    'document_type' => $candidate['document_type_label']
                ];
            }
        }

        usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($similarities, 0, $limit);
    }
}
