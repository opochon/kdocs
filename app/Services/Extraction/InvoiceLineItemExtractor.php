<?php
/**
 * K-Docs - Invoice Line Item Extractor
 * Utilise Claude AI pour extraire les lignes de facture
 */

namespace KDocs\Services\Extraction;

use KDocs\Core\Database;
use KDocs\Services\ClaudeService;
use KDocs\Models\InvoiceLineItem;

class InvoiceLineItemExtractor
{
    private ClaudeService $claude;

    /**
     * Prompt système pour l'extraction de lignes de facture
     */
    private const SYSTEM_PROMPT = <<<PROMPT
Tu es un assistant spécialisé dans l'extraction de données de factures.
Tu dois extraire les lignes de détail des factures avec précision.

Règles:
1. Extraire chaque ligne de produit/service de la facture
2. Pour chaque ligne, identifier: quantité, unité, code article, description, prix unitaire, taux TVA, montant TVA, total ligne
3. Ne pas inventer de données - mettre null si une information n'est pas présente
4. Les montants doivent être des nombres (sans symbole monétaire)
5. Le taux TVA doit être en pourcentage (ex: 7.7 pour 7.7%)
6. Retourner les données au format JSON strict

Format de réponse attendu:
{
    "success": true,
    "invoice_info": {
        "invoice_number": "xxx",
        "invoice_date": "YYYY-MM-DD",
        "supplier": "xxx",
        "total_ht": 0.00,
        "total_tva": 0.00,
        "total_ttc": 0.00
    },
    "line_items": [
        {
            "quantity": 1.0,
            "unit": "pièce",
            "code": "ART001",
            "description": "Description du produit",
            "unit_price": 100.00,
            "discount_percent": null,
            "tax_rate": 7.7,
            "tax_amount": 7.70,
            "line_total": 100.00
        }
    ]
}
PROMPT;

    public function __construct()
    {
        $this->claude = new ClaudeService();
    }

    /**
     * Vérifie si l'extracteur est disponible
     */
    public function isAvailable(): bool
    {
        return $this->claude->isConfigured();
    }

    /**
     * Extrait les lignes de facture d'un document
     *
     * @param int $documentId ID du document
     * @param bool $saveResults Sauvegarder les résultats dans la base
     * @return array Résultat de l'extraction
     */
    public function extract(int $documentId, bool $saveResults = true): array
    {
        $startTime = microtime(true);

        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'error' => 'Claude API not configured'
            ];
        }

        $db = Database::getInstance();

        // Charger le document
        $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$document) {
            return [
                'success' => false,
                'error' => 'Document not found'
            ];
        }

        // Essayer d'abord avec le contenu OCR
        $content = $document['ocr_content'] ?? '';

        $result = null;

        if (!empty($content)) {
            // Utiliser le texte OCR
            $result = $this->extractFromText($content);
        }

        // Si pas de résultat ou échec, essayer avec le fichier directement
        if ((!$result || !$result['success']) && !empty($document['file_path'])) {
            $filePath = $document['file_path'];
            if (file_exists($filePath)) {
                $result = $this->extractFromFile($filePath);
            }
        }

        if (!$result) {
            return [
                'success' => false,
                'error' => 'Unable to extract data from document'
            ];
        }

        $executionTimeMs = (int)((microtime(true) - $startTime) * 1000);

        // Sauvegarder le résultat brut
        if ($saveResults) {
            $this->saveExtractionResult($documentId, $result, $executionTimeMs);
        }

        // Sauvegarder les lignes si succès
        if ($result['success'] && !empty($result['line_items']) && $saveResults) {
            $this->saveLineItems($documentId, $result['line_items']);
        }

        $result['document_id'] = $documentId;
        $result['execution_time_ms'] = $executionTimeMs;

        return $result;
    }

    /**
     * Extrait les données depuis le texte OCR
     */
    private function extractFromText(string $content): ?array
    {
        $prompt = <<<PROMPT
Analyse cette facture et extrais toutes les lignes de détail.

Contenu de la facture:
---
$content
---

Retourne les données au format JSON.
PROMPT;

        $response = $this->claude->sendMessage($prompt, self::SYSTEM_PROMPT);

        if (!$response) {
            return null;
        }

        return $this->parseResponse($response);
    }

    /**
     * Extrait les données depuis le fichier
     */
    private function extractFromFile(string $filePath): ?array
    {
        $prompt = "Analyse cette facture et extrais toutes les lignes de détail. Retourne les données au format JSON.";

        $response = $this->claude->sendMessageWithFile($prompt, $filePath, self::SYSTEM_PROMPT);

        if (!$response) {
            return null;
        }

        return $this->parseResponse($response);
    }

    /**
     * Parse la réponse de Claude
     */
    private function parseResponse(array $response): array
    {
        $text = $this->claude->extractText($response);

        if (empty($text)) {
            return [
                'success' => false,
                'error' => 'Empty response from Claude'
            ];
        }

        // Extraire le JSON de la réponse
        $json = $this->extractJsonFromText($text);

        if (!$json) {
            return [
                'success' => false,
                'error' => 'Could not parse JSON from response',
                'raw_response' => $text
            ];
        }

        // Valider la structure
        if (!isset($json['line_items']) || !is_array($json['line_items'])) {
            return [
                'success' => false,
                'error' => 'Invalid response structure - missing line_items',
                'raw_response' => $text
            ];
        }

        // Normaliser les lignes
        $lineItems = array_map([$this, 'normalizeLineItem'], $json['line_items']);

        return [
            'success' => true,
            'invoice_info' => $json['invoice_info'] ?? null,
            'line_items' => $lineItems,
            'line_count' => count($lineItems),
            'tokens_used' => $response['usage']['total_tokens'] ?? null,
            'model' => $response['model'] ?? null
        ];
    }

    /**
     * Extrait le JSON d'une réponse textuelle
     */
    private function extractJsonFromText(string $text): ?array
    {
        // Essayer de parser directement
        $decoded = json_decode($text, true);
        if ($decoded !== null) {
            return $decoded;
        }

        // Chercher un bloc JSON dans le texte
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        // Chercher le premier { et le dernier }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $jsonStr = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($jsonStr, true);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Normalise une ligne de facture
     */
    private function normalizeLineItem(array $item): array
    {
        return [
            'quantity' => isset($item['quantity']) ? (float)$item['quantity'] : null,
            'unit' => $item['unit'] ?? null,
            'code' => $item['code'] ?? null,
            'description' => $item['description'] ?? '',
            'unit_price' => isset($item['unit_price']) ? (float)$item['unit_price'] : null,
            'discount_percent' => isset($item['discount_percent']) ? (float)$item['discount_percent'] : null,
            'tax_rate' => isset($item['tax_rate']) ? (float)$item['tax_rate'] : null,
            'tax_amount' => isset($item['tax_amount']) ? (float)$item['tax_amount'] : null,
            'line_total' => isset($item['line_total']) ? (float)$item['line_total'] : null
        ];
    }

    /**
     * Sauvegarde le résultat d'extraction
     */
    private function saveExtractionResult(int $documentId, array $result, int $executionTimeMs): void
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO invoice_extraction_results
            (document_id, extraction_type, raw_response, parsed_data, model_used, tokens_used, extraction_time_ms, success, error_message)
            VALUES (?, 'line_items', ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $documentId,
            json_encode($result),
            $result['success'] ? json_encode($result['line_items'] ?? []) : null,
            $result['model'] ?? null,
            $result['tokens_used'] ?? null,
            $executionTimeMs,
            $result['success'] ? 1 : 0,
            $result['error'] ?? null
        ]);
    }

    /**
     * Sauvegarde les lignes de facture
     */
    private function saveLineItems(int $documentId, array $lineItems): void
    {
        // Supprimer les anciennes lignes
        InvoiceLineItem::deleteForDocument($documentId);

        // Insérer les nouvelles
        foreach ($lineItems as $index => $item) {
            $item['document_id'] = $documentId;
            $item['line_number'] = $index + 1;
            $item['raw_text'] = json_encode($item);
            InvoiceLineItem::create($item);
        }
    }

    /**
     * Ré-extrait les lignes d'un document (force refresh)
     */
    public function reextract(int $documentId): array
    {
        // Supprimer les anciennes lignes
        InvoiceLineItem::deleteForDocument($documentId);

        // Extraire à nouveau
        return $this->extract($documentId, true);
    }

    /**
     * Vérifie si un document a des lignes de facture extraites
     */
    public function hasLineItems(int $documentId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT COUNT(*) FROM invoice_line_items WHERE document_id = ?");
        $stmt->execute([$documentId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Récupère l'historique des extractions pour un document
     */
    public function getExtractionHistory(int $documentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT * FROM invoice_extraction_results
            WHERE document_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
