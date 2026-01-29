<?php
/**
 * Orchestrateur de classification
 * Gère le choix entre règles et IA selon config
 * Intègre le système d'attribution et ML
 */

namespace KDocs\Services;

use KDocs\Core\Config;
use KDocs\Services\Attribution\AttributionService;
use KDocs\Services\Learning\ClassificationLearningService;
use KDocs\Services\ExtractionService;

class ClassificationService
{
    private AutoClassifierService $rules;
    private ?AIClassifierService $ai = null;
    private ?AttributionService $attribution = null;
    private ?ClassificationLearningService $learning = null;
    private ?ExtractionService $extraction = null;
    private string $method;
    private bool $autoApply;
    private float $threshold;

    public function __construct()
    {
        $config = Config::load();
        $this->method = $config['classification']['method'] ?? 'auto';
        $this->autoApply = $config['classification']['auto_apply'] ?? false;
        $this->threshold = $config['classification']['auto_apply_threshold'] ?? 0.8;

        $this->rules = new AutoClassifierService();

        if ($this->method !== 'rules') {
            try {
                $this->ai = new AIClassifierService();
                if (!$this->ai->isAvailable()) $this->ai = null;
            } catch (\Exception $e) {
                $this->ai = null;
            }
        }

        // Initialize attribution, learning and extraction services
        try {
            $this->attribution = new AttributionService();
            $this->learning = new ClassificationLearningService();
        } catch (\Exception $e) {
            // Services may not be available if tables don't exist yet
            $this->attribution = null;
            $this->learning = null;
        }

        try {
            $this->extraction = new ExtractionService();
        } catch (\Exception $e) {
            $this->extraction = null;
        }
    }
    
    public function classify(int $documentId): array
    {
        $result = [
            'method_used' => $this->method,
            'rules_result' => null,
            'ai_result' => null,
            'final' => null,
            'confidence' => 0,
            'should_review' => true,
            'auto_applied' => false,
        ];
        
        switch ($this->method) {
            case 'rules':
                $result['rules_result'] = $this->rules->classify($documentId);
                $result['final'] = $result['rules_result'];
                $result['confidence'] = $result['rules_result']['confidence'] ?? 0;
                break;
                
            case 'ai':
                if ($this->ai) {
                    $aiResult = $this->ai->classify($documentId);
                    if ($aiResult) {
                        $result['ai_result'] = $this->normalizeAIResult($aiResult);
                        $result['final'] = $result['ai_result'];
                        $result['confidence'] = $result['ai_result']['confidence'] ?? 0.7;
                        $result['method_used'] = 'ai';
                    } else {
                        // Fallback sur règles si IA échoue
                        $result['rules_result'] = $this->rules->classify($documentId);
                        $result['final'] = $result['rules_result'];
                        $result['confidence'] = $result['rules_result']['confidence'] ?? 0;
                        $result['method_used'] = 'rules_fallback';
                    }
                } else {
                    $result['rules_result'] = $this->rules->classify($documentId);
                    $result['final'] = $result['rules_result'];
                    $result['confidence'] = $result['rules_result']['confidence'] ?? 0;
                    $result['method_used'] = 'rules_fallback';
                }
                break;
                
            case 'auto':
            default:
                $result['rules_result'] = $this->rules->classify($documentId);
                $result['final'] = $result['rules_result'];
                $result['confidence'] = $result['rules_result']['confidence'] ?? 0;
                
                if ($this->ai) {
                    $aiResult = $this->ai->classify($documentId);
                    if ($aiResult) {
                        $result['ai_result'] = $this->normalizeAIResult($aiResult);
                        $result['final'] = $this->merge($result['rules_result'], $result['ai_result']);
                        $result['method_used'] = 'auto_merged';
                        // Moyenne confiance
                        $aiConf = $result['ai_result']['confidence'] ?? 0.7;
                        $result['confidence'] = ($result['confidence'] + $aiConf) / 2;
                    }
                }
                break;
        }
        
        $result['should_review'] = $result['confidence'] < $this->threshold;
        
        // Si la confiance est 0 ou très basse, recalculer avec notre méthode
        if ($result['confidence'] < 0.1 && $result['final']) {
            $result['confidence'] = $this->calculateConfidence($result['final']);
            $result['should_review'] = $result['confidence'] < $this->threshold;
        }
        
        // Auto-apply si configuré
        if ($this->autoApply && !$result['should_review'] && $result['final']) {
            $this->rules->applyClassification($documentId, $result['final']);
            $result['auto_applied'] = true;
        }

        // Process with attribution rules (new system)
        if ($this->attribution) {
            try {
                $attributionResult = $this->attribution->process($documentId, $this->autoApply);
                $result['attribution_result'] = $attributionResult;

                if ($attributionResult['actions_applied'] > 0) {
                    $result['method_used'] .= '+attribution';
                }
            } catch (\Exception $e) {
                $result['attribution_error'] = $e->getMessage();
            }
        }

        // Generate ML suggestions for remaining fields
        if ($this->learning) {
            try {
                $mlResult = $this->learning->generateSuggestions($documentId, $this->autoApply);
                $result['ml_suggestions'] = $mlResult;

                if (!empty($mlResult['auto_applied'])) {
                    $result['method_used'] .= '+ml';
                }
            } catch (\Exception $e) {
                $result['ml_error'] = $e->getMessage();
            }
        }

        // Extract data using unified extraction templates (with learning)
        if ($this->extraction) {
            try {
                $extractedData = $this->extraction->extractAll($documentId);
                $result['extracted_data'] = $extractedData;

                if (!empty($extractedData)) {
                    $result['method_used'] .= '+extraction';
                }
            } catch (\Exception $e) {
                $result['extraction_error'] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Enregistre une classification manuelle comme donnée d'entraînement
     */
    public function recordManualClassification(int $documentId, array $fields): void
    {
        if (!$this->learning) {
            return;
        }

        try {
            $this->learning->recordDocumentTraining($documentId, $fields, 'manual');
        } catch (\Exception $e) {
            // Log but don't fail
            error_log("ClassificationService: Failed to record training data: " . $e->getMessage());
        }
    }
    
    /**
     * Normalise le résultat de l'IA pour correspondre au format des règles
     */
    private function normalizeAIResult(array $aiResult): array
    {
        $matched = $aiResult['matched'] ?? [];
        
        return [
            'method' => 'ai',
            'correspondent_id' => $matched['correspondent_id'] ?? null,
            'correspondent_name' => $aiResult['correspondent'] ?? null,
            'document_type_id' => $matched['document_type_id'] ?? null,
            'document_type_name' => $aiResult['document_type'] ?? null,
            'tag_ids' => $matched['tag_ids'] ?? [],
            'tag_names' => $aiResult['tags'] ?? [],
            'document_date' => $aiResult['document_date'] ?? null,
            'amount' => $aiResult['amount'] ?? null,
            'currency' => null, // L'IA ne retourne pas toujours la devise
            'confidence' => $aiResult['confidence'] ?? 0.7,
            'summary' => $aiResult['summary'] ?? null, // Synthèse du document
            'additional_categories' => $aiResult['additional_categories'] ?? [], // Catégories supplémentaires extraites par IA
        ];
    }
    
    private function merge(array $rules, array $ai): array
    {
        $m = $rules;
        foreach (['correspondent_id', 'correspondent_name', 'document_type_id', 'document_type_name', 'doc_date', 'amount', 'currency'] as $f) {
            if (empty($m[$f]) && !empty($ai[$f])) {
                $m[$f] = $ai[$f];
                $m[$f . '_source'] = 'ai';
            }
        }
        $m['tag_ids'] = array_unique(array_merge($rules['tag_ids'] ?? [], $ai['tag_ids'] ?? []));
        $m['tag_names'] = array_unique(array_merge($rules['tag_names'] ?? [], $ai['tag_names'] ?? []));
        return $m;
    }
    
    public function getMethod(): string { return $this->method; }
    public function isAIAvailable(): bool { return $this->ai !== null; }
    public function isAttributionAvailable(): bool { return $this->attribution !== null; }
    public function isMLAvailable(): bool { return $this->learning !== null; }
    public function isExtractionAvailable(): bool { return $this->extraction !== null; }
    
    /**
     * Extrait des tags suggérés depuis le contenu OCR
     * @param string $content Contenu OCR du document
     * @return array Liste de tags suggérés
     */
    public function extractSuggestedTags(string $content): array
    {
        $tags = [];
        
        // 1. Extraire les noms propres (mots avec majuscule, > 3 lettres)
        preg_match_all('/\b([A-ZÀÂÄÉÈÊËÏÎÔÙÛÜÇ][a-zàâäéèêëïîôùûüç]{2,})\b/u', $content, $matches);
        $properNouns = array_unique($matches[1] ?? []);
        // Filtrer les mots trop communs
        $commonWords = ['Le', 'La', 'Les', 'Un', 'Une', 'Des', 'Du', 'De', 'Et', 'En', 'Au', 'Aux', 'Ce', 'Cette', 'Ces', 'Son', 'Sa', 'Ses', 'Leur', 'Leurs'];
        $properNouns = array_diff($properNouns, $commonWords);
        $tags = array_merge($tags, array_slice($properNouns, 0, 5));
        
        // 2. Extraire les années (19xx, 20xx)
        preg_match_all('/\b(19\d{2}|20\d{2})\b/', $content, $years);
        $tags = array_merge($tags, array_unique($years[0] ?? []));
        
        // 3. Mots-clés juridiques/métier courants
        $keywords = ['Tribunal', 'Arrêt', 'Jugement', 'Contrat', 'Convention', 'Facture', 'Devis', 'Attestation', 'Certificat', 'Décision', 'Accord', 'Ordonnance'];
        foreach ($keywords as $kw) {
            if (stripos($content, $kw) !== false) {
                $tags[] = $kw;
            }
        }
        
        // Dédupliquer et limiter à 10
        return array_slice(array_unique($tags), 0, 10);
    }
    
    /**
     * Calcule un score de confiance basé sur les suggestions extraites
     * @param array $suggestions Les suggestions de classification
     * @return float Score entre 0 et 1
     */
    public function calculateConfidence(array $suggestions): float
    {
        $confidence = 0.0;
        
        // +30% si type de document suggéré
        if (!empty($suggestions['document_type_id']) || !empty($suggestions['document_type_name'])) {
            $confidence += 0.30;
        }
        
        // +20% si date extraite
        if (!empty($suggestions['doc_date']) || !empty($suggestions['document_date'])) {
            $confidence += 0.20;
        }
        
        // +20% si correspondant suggéré
        if (!empty($suggestions['correspondent_id']) || !empty($suggestions['correspondent_name'])) {
            $confidence += 0.20;
        }
        
        // +15% si titre extrait (pas générique)
        $title = $suggestions['title'] ?? '';
        if (!empty($title) && !in_array(strtolower($title), ['document sans titre', 'toclassify', 'scan', 'doc'])) {
            $confidence += 0.15;
        }
        
        // +15% si des tags sont suggérés
        $tagCount = count($suggestions['tag_ids'] ?? []) + count($suggestions['tag_names'] ?? []);
        if ($tagCount > 0) {
            $confidence += 0.15;
        }
        
        return min(1.0, $confidence);
    }
}
