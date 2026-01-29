<?php
/**
 * K-Docs - Extraction Service
 * Service unifié d'extraction de données avec apprentissage automatique
 *
 * Flux: Historique → Règles → IA → Regex
 * Chaque correction manuelle améliore les futures extractions
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Models\ExtractionTemplate;
use KDocs\Models\Document;

class ExtractionService
{
    private ?ClaudeService $claudeService = null;

    public function __construct()
    {
        $this->claudeService = new ClaudeService();
    }

    /**
     * Extrait toutes les données d'un document selon les templates applicables
     *
     * @return array ['field_code' => ['value' => X, 'confidence' => 0.85, 'source' => 'history']]
     */
    public function extractAll(int $documentId): array
    {
        $document = Document::findById($documentId);
        if (!$document) {
            return [];
        }

        $correspondentId = $document['correspondent_id'] ?? null;
        $documentTypeId = $document['document_type_id'] ?? null;
        $content = $document['content'] ?? $document['ocr_text'] ?? '';

        $templates = ExtractionTemplate::getApplicable($correspondentId, $documentTypeId);
        $results = [];

        foreach ($templates as $template) {
            $result = $this->extractField($template, $document, $content);
            if ($result) {
                $results[$template['field_code']] = $result;

                // Sauvegarder dans document_extracted_data
                $this->saveExtractedValue($documentId, $template['id'], $result);
            }
        }

        return $results;
    }

    /**
     * Extrait une valeur pour un template donné
     */
    public function extractField(array $template, array $document, string $content): ?array
    {
        $correspondentId = $document['correspondent_id'] ?? null;
        $documentTypeId = $document['document_type_id'] ?? null;

        // 1. Historique (même correspondant → même valeur)
        if ($template['use_history'] && $correspondentId) {
            $result = $this->extractFromHistory($template['id'], $correspondentId, $documentTypeId);
            if ($result) {
                return $result;
            }
        }

        // 2. Règles manuelles
        if ($template['use_rules'] && !empty($template['rules'])) {
            $result = $this->extractFromRules($template, $document);
            if ($result) {
                return $result;
            }
        }

        // 3. IA (Claude)
        if ($template['use_ai'] && !empty($template['ai_prompt']) && $this->claudeService->isConfigured()) {
            $result = $this->extractFromAI($template, $content, $document);
            if ($result) {
                return $result;
            }
        }

        // 4. Regex
        if ($template['use_regex'] && !empty($template['regex_pattern'])) {
            $result = $this->extractFromRegex($template, $content);
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Extrait depuis l'historique (apprentissage)
     */
    private function extractFromHistory(int $templateId, int $correspondentId, ?int $documentTypeId): ?array
    {
        $db = Database::getInstance();

        // Chercher la valeur la plus utilisée pour ce correspondant
        $sql = "SELECT extracted_value, confidence, times_used, times_confirmed
                FROM extraction_history
                WHERE template_id = ? AND correspondent_id = ?";
        $params = [$templateId, $correspondentId];

        if ($documentTypeId) {
            $sql .= " AND (document_type_id = ? OR document_type_id IS NULL)";
            $params[] = $documentTypeId;
        }

        $sql .= " ORDER BY confidence DESC, times_used DESC LIMIT 1";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetch();

        if ($history && $history['confidence'] >= 0.30) {
            return [
                'value' => $history['extracted_value'],
                'confidence' => (float) $history['confidence'],
                'source' => 'history',
                'times_used' => (int) $history['times_used']
            ];
        }

        return null;
    }

    /**
     * Extrait depuis les règles manuelles
     */
    private function extractFromRules(array $template, array $document): ?array
    {
        $rules = ExtractionTemplate::parseRules($template);

        foreach ($rules as $rule) {
            if (!isset($rule['if']) || !isset($rule['then'])) {
                continue;
            }

            $conditions = $rule['if'];
            $match = true;

            foreach ($conditions as $field => $expected) {
                $actual = $document[$field] ?? null;

                if (is_array($expected)) {
                    // Liste de valeurs possibles
                    if (!in_array($actual, $expected)) {
                        $match = false;
                        break;
                    }
                } else {
                    // Valeur unique
                    if ($actual != $expected) {
                        $match = false;
                        break;
                    }
                }
            }

            if ($match) {
                return [
                    'value' => $rule['then'],
                    'confidence' => 1.0,
                    'source' => 'rules'
                ];
            }
        }

        return null;
    }

    /**
     * Extrait via IA (Claude)
     */
    private function extractFromAI(array $template, string $content, array $document): ?array
    {
        if (empty($content) || strlen($content) < 50) {
            return null;
        }

        // Limiter le contenu pour l'API
        $contentTruncated = mb_substr($content, 0, 4000);

        // Construire le contexte
        $context = "Document: " . ($document['title'] ?? $document['original_filename'] ?? 'Sans titre');
        if (!empty($document['correspondent_name'])) {
            $context .= "\nCorrespondant: " . $document['correspondent_name'];
        }
        if (!empty($document['document_type_name'])) {
            $context .= "\nType: " . $document['document_type_name'];
        }

        // Options disponibles si c'est un select
        $optionsText = '';
        if (in_array($template['field_type'], ['select', 'multi_select']) && !empty($template['options'])) {
            $options = json_decode($template['options'], true) ?: [];
            $optionValues = array_map(function($opt) {
                return is_string($opt) ? $opt : ($opt['value'] ?? '');
            }, $options);
            if (!empty($optionValues)) {
                $optionsText = "\n\nValeurs possibles: " . implode(', ', $optionValues);
            }
        }

        $prompt = $template['ai_prompt'] . $optionsText . "\n\n" . $context . "\n\nContenu du document:\n" . $contentTruncated;

        $systemPrompt = "Tu es un assistant qui extrait des informations de documents. Réponds de manière concise avec uniquement la valeur demandée, sans phrase complète.";

        try {
            $response = $this->claudeService->sendMessage($prompt, $systemPrompt);

            if (!empty($response)) {
                // Extraire le texte de la réponse
                $text = $this->claudeService->extractText($response);
                if (empty($text)) {
                    return null;
                }

                // Nettoyer la réponse
                $value = trim($text);
                $value = preg_replace('/^(Le |La |L\'|The )/i', '', $value);
                $value = trim($value, '."\'');

                // Vérifier que la valeur est dans les options si c'est un select
                if (in_array($template['field_type'], ['select', 'multi_select']) && !empty($template['options'])) {
                    $options = json_decode($template['options'], true) ?: [];
                    $validValues = array_map(function($opt) {
                        return is_string($opt) ? $opt : ($opt['value'] ?? '');
                    }, $options);

                    if (!in_array($value, $validValues)) {
                        // Essayer de matcher partiellement
                        foreach ($validValues as $valid) {
                            if (stripos($value, $valid) !== false || stripos($valid, $value) !== false) {
                                $value = $valid;
                                break;
                            }
                        }
                    }
                }

                if (!empty($value) && strlen($value) < 500) {
                    return [
                        'value' => $value,
                        'confidence' => 0.75,
                        'source' => 'ai'
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log("ExtractionService AI error: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extrait via Regex
     */
    private function extractFromRegex(array $template, string $content): ?array
    {
        $pattern = $template['regex_pattern'];

        // S'assurer que le pattern est valide
        if (@preg_match($pattern, '') === false) {
            error_log("ExtractionService: Invalid regex pattern: " . $pattern);
            return null;
        }

        if (preg_match($pattern, $content, $matches)) {
            // Utiliser le premier groupe de capture ou le match complet
            $value = $matches[1] ?? $matches[0];
            $value = trim($value);

            if (!empty($value)) {
                return [
                    'value' => $value,
                    'confidence' => 0.70,
                    'source' => 'regex'
                ];
            }
        }

        return null;
    }

    /**
     * Sauvegarde une valeur extraite
     */
    private function saveExtractedValue(int $documentId, int $templateId, array $result): void
    {
        $db = Database::getInstance();

        // Upsert dans document_extracted_data
        $stmt = $db->prepare("
            INSERT INTO document_extracted_data
                (document_id, template_id, value, confidence, source, extracted_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                value = VALUES(value),
                confidence = VALUES(confidence),
                source = VALUES(source),
                extracted_at = NOW()
        ");

        $stmt->execute([
            $documentId,
            $templateId,
            $result['value'],
            $result['confidence'],
            $result['source']
        ]);
    }

    /**
     * Confirme une valeur extraite (utilisateur clique "OK")
     */
    public function confirmValue(int $documentId, string $fieldCode, ?int $userId = null): bool
    {
        $template = ExtractionTemplate::findByCode($fieldCode);
        if (!$template) {
            return false;
        }

        $db = Database::getInstance();

        // Marquer comme confirmé
        $stmt = $db->prepare("
            UPDATE document_extracted_data
            SET is_confirmed = TRUE, confirmed_at = NOW()
            WHERE document_id = ? AND template_id = ?
        ");
        $stmt->execute([$documentId, $template['id']]);

        // Incrémenter times_confirmed dans l'historique
        $document = Document::findById($documentId);
        if ($document && $template['learn_from_corrections']) {
            $this->updateHistory($template['id'], $document, null, true, false);
        }

        // Audit
        $this->logAudit($documentId, $template['id'], $fieldCode, 'confirmed', null, null, $userId);

        return true;
    }

    /**
     * Corrige une valeur extraite (apprentissage)
     */
    public function correctValue(int $documentId, string $fieldCode, string $newValue, ?int $userId = null): bool
    {
        $template = ExtractionTemplate::findByCode($fieldCode);
        if (!$template) {
            return false;
        }

        $db = Database::getInstance();

        // Récupérer l'ancienne valeur
        $stmt = $db->prepare("
            SELECT value FROM document_extracted_data
            WHERE document_id = ? AND template_id = ?
        ");
        $stmt->execute([$documentId, $template['id']]);
        $old = $stmt->fetch();
        $oldValue = $old['value'] ?? null;

        // Mettre à jour
        $stmt = $db->prepare("
            INSERT INTO document_extracted_data
                (document_id, template_id, value, confidence, source, is_confirmed, is_corrected, original_value, confirmed_at)
            VALUES (?, ?, ?, 1.0, 'manual', TRUE, TRUE, ?, NOW())
            ON DUPLICATE KEY UPDATE
                original_value = COALESCE(original_value, value),
                value = VALUES(value),
                confidence = 1.0,
                source = 'manual',
                is_confirmed = TRUE,
                is_corrected = TRUE,
                confirmed_at = NOW()
        ");
        $stmt->execute([$documentId, $template['id'], $newValue, $oldValue]);

        // Apprentissage: enregistrer dans l'historique
        $document = Document::findById($documentId);
        if ($document && $template['learn_from_corrections']) {
            $this->learnFromCorrection($template['id'], $document, $newValue);
        }

        // Audit
        $this->logAudit($documentId, $template['id'], $fieldCode, 'corrected', $oldValue, $newValue, $userId);

        return true;
    }

    /**
     * Apprend d'une correction manuelle
     */
    private function learnFromCorrection(int $templateId, array $document, string $newValue): void
    {
        $correspondentId = $document['correspondent_id'] ?? null;
        $documentTypeId = $document['document_type_id'] ?? null;

        if (!$correspondentId) {
            return; // Pas d'apprentissage sans correspondant
        }

        $db = Database::getInstance();
        $normalizedValue = mb_strtolower(trim($newValue));

        // Upsert dans extraction_history
        $stmt = $db->prepare("
            INSERT INTO extraction_history
                (template_id, correspondent_id, document_type_id, extracted_value, normalized_value,
                 times_used, times_confirmed, confidence, source, first_used_at, last_used_at)
            VALUES (?, ?, ?, ?, ?, 1, 1, 0.60, 'manual', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                times_used = times_used + 1,
                times_confirmed = times_confirmed + 1,
                confidence = LEAST(0.95, confidence + 0.10),
                last_used_at = NOW()
        ");

        $stmt->execute([
            $templateId,
            $correspondentId,
            $documentTypeId,
            $newValue,
            $normalizedValue
        ]);
    }

    /**
     * Met à jour les stats d'historique
     */
    private function updateHistory(int $templateId, array $document, ?string $value, bool $confirmed, bool $corrected): void
    {
        $correspondentId = $document['correspondent_id'] ?? null;
        if (!$correspondentId) {
            return;
        }

        $db = Database::getInstance();

        if ($confirmed && !$corrected) {
            // Juste une confirmation
            $stmt = $db->prepare("
                UPDATE extraction_history
                SET times_confirmed = times_confirmed + 1,
                    confidence = LEAST(0.95, confidence + 0.05),
                    last_used_at = NOW()
                WHERE template_id = ? AND correspondent_id = ?
            ");
            $stmt->execute([$templateId, $correspondentId]);
        }
    }

    /**
     * Log d'audit
     */
    private function logAudit(int $documentId, int $templateId, string $fieldCode,
                               string $action, ?string $oldValue, ?string $newValue, ?int $userId): void
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO extraction_audit_log
                (document_id, template_id, field_code, action, old_value, new_value, user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $documentId,
            $templateId,
            $fieldCode,
            $action,
            $oldValue,
            $newValue,
            $userId
        ]);
    }

    /**
     * Récupère les données extraites d'un document
     */
    public function getExtractedData(int $documentId): array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT
                et.field_code,
                et.name as field_name,
                et.field_type,
                et.show_confidence,
                et.options,
                ded.value,
                ded.confidence,
                ded.source,
                ded.is_confirmed,
                ded.is_corrected
            FROM document_extracted_data ded
            JOIN extraction_templates et ON et.id = ded.template_id
            WHERE ded.document_id = ?
            ORDER BY et.display_order
        ");

        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }

    /**
     * Récupère les suggestions pour un champ (valeurs fréquentes)
     */
    public function getSuggestions(string $fieldCode, ?int $correspondentId = null, int $limit = 5): array
    {
        $template = ExtractionTemplate::findByCode($fieldCode);
        if (!$template) {
            return [];
        }

        $db = Database::getInstance();

        $sql = "SELECT extracted_value, confidence, times_used
                FROM extraction_history
                WHERE template_id = ?";
        $params = [$template['id']];

        if ($correspondentId) {
            $sql .= " AND correspondent_id = ?";
            $params[] = $correspondentId;
        }

        $sql .= " ORDER BY times_used DESC, confidence DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
