<?php
/**
 * Orchestrateur de classification
 * Gère le choix entre règles et IA selon config
 */

namespace KDocs\Services;

use KDocs\Core\Config;

class ClassificationService
{
    private AutoClassifierService $rules;
    private ?AIClassifierService $ai = null;
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
        
        // Auto-apply si configuré
        if ($this->autoApply && !$result['should_review'] && $result['final']) {
            $this->rules->applyClassification($documentId, $result['final']);
            $result['auto_applied'] = true;
        }
        
        return $result;
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
}
