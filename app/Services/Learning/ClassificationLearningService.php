<?php
/**
 * K-Docs - Classification Learning Service
 * Service principal pour l'apprentissage et les suggestions ML
 */

namespace KDocs\Services\Learning;

use KDocs\Core\Database;
use KDocs\Models\ClassificationTrainingData;
use KDocs\Models\ClassificationSuggestion;
use KDocs\Models\ClassificationAuditLog;

class ClassificationLearningService
{
    private FeatureExtractor $featureExtractor;
    private SimilarityMatcher $similarityMatcher;

    /**
     * Seuil de confiance pour l'auto-application
     */
    private const AUTO_APPLY_THRESHOLD = 0.85;

    /**
     * Seuil minimum pour créer une suggestion
     */
    private const SUGGESTION_THRESHOLD = 0.50;

    /**
     * Champs supportés pour le ML
     */
    private const SUPPORTED_FIELDS = ['compte_comptable', 'centre_cout', 'projet'];

    public function __construct()
    {
        $this->featureExtractor = new FeatureExtractor();
        $this->similarityMatcher = new SimilarityMatcher();
    }

    /**
     * Enregistre une classification manuelle comme donnée d'entraînement
     *
     * @param int $documentId ID du document
     * @param string $fieldCode Code du champ
     * @param string $fieldValue Valeur attribuée
     * @param string $source Source (manual, rules, ai)
     * @param float $confidence Confiance
     */
    public function recordTraining(
        int $documentId,
        string $fieldCode,
        string $fieldValue,
        string $source = 'manual',
        float $confidence = 1.0
    ): int {
        // Extraire les features
        $features = $this->featureExtractor->extract($documentId);

        // Enregistrer les données d'entraînement
        return ClassificationTrainingData::upsert([
            'document_id' => $documentId,
            'field_code' => $fieldCode,
            'field_value' => $fieldValue,
            'features' => $features,
            'source' => $source,
            'confidence' => $confidence
        ]);
    }

    /**
     * Enregistre toutes les classifications d'un document
     */
    public function recordDocumentTraining(int $documentId, array $classifications, string $source = 'manual'): array
    {
        $recorded = [];

        foreach ($classifications as $fieldCode => $fieldValue) {
            if (!in_array($fieldCode, self::SUPPORTED_FIELDS)) {
                continue;
            }

            if (empty($fieldValue)) {
                continue;
            }

            $trainingId = $this->recordTraining($documentId, $fieldCode, $fieldValue, $source);
            $recorded[$fieldCode] = $trainingId;
        }

        return $recorded;
    }

    /**
     * Génère des suggestions pour un document
     *
     * @param int $documentId ID du document
     * @param bool $autoApply Appliquer automatiquement si confiance haute
     * @param int|null $userId ID utilisateur pour l'audit
     * @return array Suggestions générées
     */
    public function generateSuggestions(int $documentId, bool $autoApply = false, ?int $userId = null): array
    {
        $db = Database::getInstance();

        // Charger le document pour voir quels champs sont vides
        $stmt = $db->prepare("SELECT compte_comptable, centre_cout, projet FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$document) {
            return ['error' => 'Document not found'];
        }

        // Supprimer les anciennes suggestions pendantes
        ClassificationSuggestion::deleteForDocument($documentId);

        $result = [
            'document_id' => $documentId,
            'suggestions' => [],
            'auto_applied' => [],
            'skipped' => []
        ];

        foreach (self::SUPPORTED_FIELDS as $fieldCode) {
            // Ignorer les champs déjà remplis
            if (!empty($document[$fieldCode])) {
                $result['skipped'][] = [
                    'field' => $fieldCode,
                    'reason' => 'Already filled',
                    'current_value' => $document[$fieldCode]
                ];
                continue;
            }

            // Prédire la valeur
            $prediction = $this->similarityMatcher->predict($documentId, $fieldCode);

            if (!$prediction) {
                $result['skipped'][] = [
                    'field' => $fieldCode,
                    'reason' => 'No similar documents found'
                ];
                continue;
            }

            if ($prediction['confidence'] < self::SUGGESTION_THRESHOLD) {
                $result['skipped'][] = [
                    'field' => $fieldCode,
                    'reason' => 'Confidence too low',
                    'confidence' => $prediction['confidence']
                ];
                continue;
            }

            // Auto-apply si confiance suffisante
            if ($autoApply && $prediction['confidence'] >= self::AUTO_APPLY_THRESHOLD) {
                $this->applyPrediction($documentId, $fieldCode, $prediction, $userId);
                $result['auto_applied'][] = [
                    'field' => $fieldCode,
                    'value' => $prediction['predicted_value'],
                    'confidence' => $prediction['confidence']
                ];
            } else {
                // Créer une suggestion
                $suggestionId = ClassificationSuggestion::create([
                    'document_id' => $documentId,
                    'field_code' => $fieldCode,
                    'suggested_value' => $prediction['predicted_value'],
                    'confidence' => $prediction['confidence'],
                    'source' => 'ml',
                    'similar_documents' => $prediction['similar_documents']
                ]);

                $result['suggestions'][] = [
                    'id' => $suggestionId,
                    'field' => $fieldCode,
                    'value' => $prediction['predicted_value'],
                    'confidence' => $prediction['confidence'],
                    'similar_count' => $prediction['total_similar'],
                    'candidates' => $prediction['all_candidates']
                ];
            }
        }

        return $result;
    }

    /**
     * Applique une prédiction sur un document
     */
    private function applyPrediction(int $documentId, string $fieldCode, array $prediction, ?int $userId): void
    {
        $db = Database::getInstance();

        // Mettre à jour le document
        $stmt = $db->prepare("UPDATE documents SET $fieldCode = ?, last_classified_at = NOW(), last_classified_by = 'ml' WHERE id = ?");
        $stmt->execute([$prediction['predicted_value'], $documentId]);

        // Logger l'audit
        ClassificationAuditLog::log([
            'document_id' => $documentId,
            'field_code' => $fieldCode,
            'old_value' => null,
            'new_value' => $prediction['predicted_value'],
            'change_source' => 'ml',
            'change_reason' => sprintf(
                'Auto-applied with %.0f%% confidence based on %d similar documents',
                $prediction['confidence'] * 100,
                $prediction['total_similar']
            ),
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        // Enregistrer comme donnée d'entraînement (avec confiance ML)
        $this->recordTraining($documentId, $fieldCode, $prediction['predicted_value'], 'ml', $prediction['confidence']);
    }

    /**
     * Applique une suggestion manuellement
     */
    public function applySuggestion(int $suggestionId, int $userId): array
    {
        $suggestion = ClassificationSuggestion::find($suggestionId);

        if (!$suggestion) {
            return ['error' => 'Suggestion not found'];
        }

        if ($suggestion['status'] !== 'pending') {
            return ['error' => 'Suggestion already processed'];
        }

        $db = Database::getInstance();
        $fieldCode = $suggestion['field_code'];
        $documentId = $suggestion['document_id'];

        // Récupérer l'ancienne valeur
        $stmt = $db->prepare("SELECT $fieldCode FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $oldValue = $stmt->fetchColumn();

        // Appliquer la valeur
        $stmt = $db->prepare("UPDATE documents SET $fieldCode = ?, last_classified_at = NOW(), last_classified_by = 'ml' WHERE id = ?");
        $stmt->execute([$suggestion['suggested_value'], $documentId]);

        // Marquer la suggestion comme appliquée
        ClassificationSuggestion::apply($suggestionId, $userId);

        // Logger l'audit
        ClassificationAuditLog::log([
            'document_id' => $documentId,
            'field_code' => $fieldCode,
            'old_value' => $oldValue,
            'new_value' => $suggestion['suggested_value'],
            'change_source' => 'ml',
            'suggestion_id' => $suggestionId,
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        // Enregistrer comme donnée d'entraînement (manual car validée par l'utilisateur)
        $this->recordTraining($documentId, $fieldCode, $suggestion['suggested_value'], 'manual', 1.0);

        return [
            'success' => true,
            'document_id' => $documentId,
            'field' => $fieldCode,
            'old_value' => $oldValue,
            'new_value' => $suggestion['suggested_value']
        ];
    }

    /**
     * Ignore une suggestion
     */
    public function ignoreSuggestion(int $suggestionId): bool
    {
        return ClassificationSuggestion::ignore($suggestionId);
    }

    /**
     * Récupère les statistiques du système ML
     */
    public function getStats(): array
    {
        return [
            'training_data' => ClassificationTrainingData::getStats(),
            'suggestions' => ClassificationSuggestion::getStats(),
            'supported_fields' => self::SUPPORTED_FIELDS,
            'thresholds' => [
                'auto_apply' => self::AUTO_APPLY_THRESHOLD,
                'suggestion' => self::SUGGESTION_THRESHOLD
            ]
        ];
    }

    /**
     * Récupère les suggestions pendantes pour un document
     */
    public function getDocumentSuggestions(int $documentId): array
    {
        $suggestions = ClassificationSuggestion::getForDocument($documentId, 'pending');

        // Enrichir avec les labels
        $db = Database::getInstance();

        foreach ($suggestions as &$suggestion) {
            $fieldCode = $suggestion['field_code'];

            // Chercher le label de la valeur suggérée
            $stmt = $db->prepare("
                SELECT option_label FROM classification_field_options
                WHERE field_code = ? AND option_value = ?
            ");
            $stmt->execute([$fieldCode, $suggestion['suggested_value']]);
            $option = $stmt->fetch();

            $suggestion['value_label'] = $option ? $option['option_label'] : $suggestion['suggested_value'];
            $suggestion['similar_documents'] = json_decode($suggestion['similar_documents'], true) ?? [];
        }

        return $suggestions;
    }

    /**
     * Retourne les seuils configurés
     */
    public function getThresholds(): array
    {
        return [
            'auto_apply' => self::AUTO_APPLY_THRESHOLD,
            'suggestion' => self::SUGGESTION_THRESHOLD
        ];
    }
}
