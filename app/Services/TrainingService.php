<?php
/**
 * K-Docs - Training Service
 * Stores user corrections and learns classification patterns
 * Extracted from POC 08_training.php
 */

namespace KDocs\Services;

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Helpers\AIHelper;

class TrainingService
{
    private string $trainingFile;
    private array $trainingData;
    private ?EmbeddingService $embeddingService = null;
    private float $minSimilarity = 0.85;
    private bool $autoLearnRules = true;

    public function __construct()
    {
        $config = Config::load();

        // Training file location
        $this->trainingFile = $config['ai']['training']['file']
            ?? dirname(__DIR__, 2) . '/storage/training.json';

        $this->minSimilarity = $config['ai']['training']['min_similarity'] ?? 0.85;
        $this->autoLearnRules = $config['ai']['training']['auto_learn_rules'] ?? true;

        $this->loadTrainingData();
    }

    /**
     * Store a user correction
     */
    public function storeCorrection(
        string $text,
        string $suggestedType,
        string $correctedType,
        array $correctedFields = [],
        ?int $documentId = null
    ): bool {
        // Generate embedding for the text
        $embedding = $this->getEmbedding($text);

        $correction = [
            'id' => uniqid('corr_'),
            'timestamp' => date('c'),
            'document_id' => $documentId,
            'text_preview' => mb_substr($text, 0, 500),
            'text_hash' => hash('sha256', $text),
            'suggested_type' => $suggestedType,
            'corrected_type' => $correctedType,
            'corrected_fields' => $correctedFields,
            'embedding' => $embedding,
        ];

        $this->trainingData['corrections'][] = $correction;

        // Auto-learn rules if enabled
        if ($this->autoLearnRules) {
            $this->learnFromCorrection($correction, $text);
        }

        return $this->saveTrainingData();
    }

    /**
     * Get classification based on trained data
     * Uses embedding similarity to find similar past corrections
     */
    public function getTrainedClassification(string $text): ?array
    {
        if (empty($this->trainingData['corrections'])) {
            return null;
        }

        // Generate embedding for current text
        $currentEmbedding = $this->getEmbedding($text);
        if (!$currentEmbedding) {
            return null;
        }

        $bestMatch = null;
        $bestSimilarity = 0;

        foreach ($this->trainingData['corrections'] as $correction) {
            if (empty($correction['embedding'])) {
                continue;
            }

            $similarity = AIHelper::cosineSimilarity($currentEmbedding, $correction['embedding']);

            if ($similarity > $bestSimilarity && $similarity >= $this->minSimilarity) {
                $bestSimilarity = $similarity;
                $bestMatch = $correction;
            }
        }

        if ($bestMatch) {
            return [
                'type' => $bestMatch['corrected_type'],
                'confidence' => $bestSimilarity,
                'method' => 'training_similarity',
                'matched_correction_id' => $bestMatch['id'],
                'fields' => $bestMatch['corrected_fields'] ?? [],
            ];
        }

        return null;
    }

    /**
     * Apply learned rules to text
     */
    public function applyLearnedRules(string $text): ?array
    {
        if (empty($this->trainingData['rules'])) {
            return null;
        }

        $textLower = mb_strtolower($text);

        foreach ($this->trainingData['rules'] as $rule) {
            $allMatch = true;

            foreach ($rule['patterns'] as $pattern) {
                if (!str_contains($textLower, mb_strtolower($pattern))) {
                    $allMatch = false;
                    break;
                }
            }

            if ($allMatch) {
                return [
                    'type' => $rule['type'],
                    'confidence' => $rule['confidence'] ?? 0.75,
                    'method' => 'learned_rule',
                    'rule_id' => $rule['id'],
                ];
            }
        }

        return null;
    }

    /**
     * Learn patterns from a correction
     */
    private function learnFromCorrection(array $correction, string $fullText): void
    {
        $type = $correction['corrected_type'];
        $textLower = mb_strtolower($fullText);

        // Extract potential patterns
        $potentialPatterns = [];

        // Type keywords
        $typeKeywords = [
            'facture' => ['facture', 'invoice', 'montant dû', 'total ttc', 'n° facture'],
            'contrat' => ['contrat', 'convention', 'parties conviennent', 'signataires'],
            'courrier' => ['madame', 'monsieur', 'cher', 'veuillez agréer', 'cordialement'],
            'rapport' => ['rapport', 'analyse', 'conclusion', 'synthèse', 'résumé'],
            'devis' => ['devis', 'estimation', 'offre', 'proposition', 'quote'],
            'bon_commande' => ['bon de commande', 'purchase order', 'commande n°'],
            'releve' => ['relevé', 'extrait', 'solde', 'mouvement'],
        ];

        $typeLower = mb_strtolower($type);
        $keywords = $typeKeywords[$typeLower] ?? [];

        foreach ($keywords as $keyword) {
            if (str_contains($textLower, $keyword)) {
                $potentialPatterns[] = $keyword;
            }
        }

        // If we found patterns, create/update rule
        if (count($potentialPatterns) >= 2) {
            $ruleId = 'rule_' . md5(implode('_', $potentialPatterns) . '_' . $type);

            // Check if rule exists
            $existingIndex = null;
            foreach ($this->trainingData['rules'] as $index => $rule) {
                if ($rule['id'] === $ruleId) {
                    $existingIndex = $index;
                    break;
                }
            }

            if ($existingIndex !== null) {
                // Increase confidence
                $this->trainingData['rules'][$existingIndex]['matches']++;
                $this->trainingData['rules'][$existingIndex]['confidence'] = min(
                    0.95,
                    0.7 + ($this->trainingData['rules'][$existingIndex]['matches'] * 0.05)
                );
            } else {
                // Create new rule
                $this->trainingData['rules'][] = [
                    'id' => $ruleId,
                    'type' => $type,
                    'patterns' => array_slice($potentialPatterns, 0, 3),
                    'confidence' => 0.7,
                    'matches' => 1,
                    'created_at' => date('c'),
                ];
            }
        }
    }

    /**
     * Get training statistics
     */
    public function getStatistics(): array
    {
        $corrections = $this->trainingData['corrections'] ?? [];
        $rules = $this->trainingData['rules'] ?? [];

        // Count corrections by type
        $typeCount = [];
        foreach ($corrections as $c) {
            $type = $c['corrected_type'];
            $typeCount[$type] = ($typeCount[$type] ?? 0) + 1;
        }

        return [
            'total_corrections' => count($corrections),
            'total_rules' => count($rules),
            'corrections_by_type' => $typeCount,
            'rules_by_confidence' => $this->groupRulesByConfidence($rules),
            'last_correction' => !empty($corrections) ? end($corrections)['timestamp'] : null,
        ];
    }

    /**
     * Export training data
     */
    public function export(): array
    {
        return $this->trainingData;
    }

    /**
     * Import training data
     */
    public function import(array $data): bool
    {
        // Merge with existing
        if (!empty($data['corrections'])) {
            $existingHashes = array_column($this->trainingData['corrections'] ?? [], 'text_hash');

            foreach ($data['corrections'] as $correction) {
                if (!in_array($correction['text_hash'] ?? '', $existingHashes)) {
                    $this->trainingData['corrections'][] = $correction;
                }
            }
        }

        if (!empty($data['rules'])) {
            $existingIds = array_column($this->trainingData['rules'] ?? [], 'id');

            foreach ($data['rules'] as $rule) {
                if (!in_array($rule['id'] ?? '', $existingIds)) {
                    $this->trainingData['rules'][] = $rule;
                }
            }
        }

        return $this->saveTrainingData();
    }

    /**
     * Clear all training data
     */
    public function clear(): bool
    {
        $this->trainingData = [
            'version' => '1.0',
            'created_at' => date('c'),
            'corrections' => [],
            'rules' => [],
        ];

        return $this->saveTrainingData();
    }

    /**
     * Load training data from file
     */
    private function loadTrainingData(): void
    {
        if (file_exists($this->trainingFile)) {
            $content = file_get_contents($this->trainingFile);
            $this->trainingData = json_decode($content, true) ?? [];
        }

        // Initialize structure if needed
        if (empty($this->trainingData)) {
            $this->trainingData = [
                'version' => '1.0',
                'created_at' => date('c'),
                'corrections' => [],
                'rules' => [],
            ];
        }
    }

    /**
     * Save training data to file
     */
    private function saveTrainingData(): bool
    {
        $this->trainingData['updated_at'] = date('c');

        $dir = dirname($this->trainingFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($this->trainingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents($this->trainingFile, $json) !== false;
    }

    /**
     * Get embedding for text
     */
    private function getEmbedding(string $text): ?array
    {
        if (!$this->embeddingService) {
            $this->embeddingService = new EmbeddingService();
        }

        if (!$this->embeddingService->isAvailable()) {
            return null;
        }

        return $this->embeddingService->embed($text);
    }

    /**
     * Group rules by confidence level
     */
    private function groupRulesByConfidence(array $rules): array
    {
        $groups = ['high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($rules as $rule) {
            $conf = $rule['confidence'] ?? 0.5;
            if ($conf >= 0.85) {
                $groups['high']++;
            } elseif ($conf >= 0.7) {
                $groups['medium']++;
            } else {
                $groups['low']++;
            }
        }

        return $groups;
    }
}
