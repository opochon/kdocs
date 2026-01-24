<?php
/**
 * K-Docs - WebhookAction
 * Envoie un webhook depuis un workflow
 */

namespace KDocs\Workflow\Nodes\Actions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class WebhookAction extends AbstractNodeExecutor
{
    /**
     * Placeholders disponibles pour les templates webhook
     */
    private array $placeholders = [
        '{correspondent}' => 'correspondent_name',
        '{document_type}' => 'document_type_name',
        '{title}' => 'title',
        '{created}' => 'created_at',
        '{created_year}' => 'created_year',
        '{created_month}' => 'created_month',
        '{created_day}' => 'created_day',
        '{added}' => 'added_at',
        '{added_year}' => 'added_year',
        '{added_month}' => 'added_month',
        '{added_day}' => 'added_day',
        '{asn}' => 'archive_serial_number',
        '{owner}' => 'owner_name',
        '{original_filename}' => 'original_filename',
        '{amount}' => 'amount',
    ];
    
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        $url = $config['url'] ?? null;
        if (!$url) {
            return ExecutionResult::failed('URL webhook non spécifiée');
        }
        
        // Valider l'URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ExecutionResult::failed("URL webhook invalide: $url");
        }
        
        // Récupérer les données du document
        $document = $this->getDocumentData($context->documentId);
        if (!$document) {
            return ExecutionResult::failed('Document non trouvé');
        }
        
        // Préparer les données par défaut
        $data = [
            'document_id' => $document['id'],
            'title' => $document['title'] ?? null,
            'correspondent' => $document['correspondent_name'] ?? null,
            'document_type' => $document['document_type_name'] ?? null,
            'created_at' => $document['created_at'] ?? null,
            'original_filename' => $document['original_filename'] ?? null,
            'amount' => $document['amount'] ?? null,
        ];
        
        // Ajouter les données custom si spécifiées
        if (!empty($config['payload'])) {
            $customPayload = is_string($config['payload']) 
                ? json_decode($config['payload'], true) 
                : $config['payload'];
            
            if (is_array($customPayload)) {
                // Remplacer les placeholders dans les valeurs
                foreach ($customPayload as $key => $value) {
                    if (is_string($value)) {
                        $data[$key] = $this->replacePlaceholders($value, $document);
                    } else {
                        $data[$key] = $value;
                    }
                }
            }
        }
        
        // Méthode HTTP
        $method = strtoupper($config['method'] ?? 'POST');
        $timeout = (int)($config['timeout'] ?? 30);
        
        // Headers
        $headers = [];
        if (!empty($config['headers'])) {
            $customHeaders = is_string($config['headers']) 
                ? json_decode($config['headers'], true) 
                : $config['headers'];
            
            if (is_array($customHeaders)) {
                foreach ($customHeaders as $key => $value) {
                    $headers[] = "$key: $value";
                }
            }
        }
        
        // Format de données
        $asJson = $config['as_json'] ?? true;
        $useParams = $config['use_params'] ?? false;
        
        // Vérifier si on doit inclure le document
        $includeDocument = !empty($config['include_document']);
        $filePath = null;
        if ($includeDocument) {
            $filePath = $document['file_path'] ?? null;
            if ($filePath) {
                // Si le chemin est relatif, construire le chemin complet
                if (!file_exists($filePath)) {
                    $configStorage = \KDocs\Core\Config::load();
                    $documentsPath = $configStorage['storage']['documents'] ?? __DIR__ . '/../../../../storage/documents';
                    $fullPath = $documentsPath . '/' . basename($filePath);
                    if (file_exists($fullPath)) {
                        $filePath = $fullPath;
                    }
                }
                
                if (!file_exists($filePath)) {
                    error_log("WebhookAction: Fichier document introuvable: " . ($document['file_path'] ?? 'N/A'));
                    $includeDocument = false;
                }
            } else {
                $includeDocument = false;
            }
        }
        
        // Préparer les données POST
        $postData = null;
        $isGet = false;
        
        if ($includeDocument) {
            // POST avec multipart/form-data (document inclus)
            $postData = [];
            foreach ($data as $key => $value) {
                $postData[$key] = $value;
            }
            $postData['document'] = new \CURLFile($filePath);
            $headers[] = 'Content-Type: multipart/form-data';
        } elseif ($useParams && $method === 'GET') {
            // GET avec params dans l'URL
            $url .= '?' . http_build_query($data);
            $isGet = true;
        } elseif ($asJson) {
            // POST JSON
            $postData = json_encode($data);
            $headers[] = 'Content-Type: application/json';
        } else {
            // POST form-urlencoded
            $postData = http_build_query($data);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        
        // Exécuter la requête
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            
            if (!$isGet && $postData !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            } elseif ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);
            
            // Logger l'exécution
            $this->logWebhookExecution($url, $data, $httpCode, $response, $error);
            
            if ($curlErrno !== CURLE_OK || $error) {
                return ExecutionResult::failed("Webhook failed: $error (cURL error $curlErrno)");
            }
            
            if ($httpCode >= 400) {
                $errorMsg = "Webhook returned error code: $httpCode";
                if ($response) {
                    $errorMsg .= " - Response: " . substr($response, 0, 200);
                }
                return ExecutionResult::failed($errorMsg);
            }
            
            return ExecutionResult::success([
                'url' => $url,
                'http_code' => $httpCode,
                'response' => substr($response, 0, 500),
                'method' => $isGet ? 'GET' : $method,
                'document_included' => $includeDocument
            ]);
        } catch (\Exception $e) {
            return ExecutionResult::failed('Erreur webhook: ' . $e->getMessage());
        }
    }
    
    /**
     * Récupère les données complètes du document
     */
    private function getDocumentData(int $documentId): ?array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT 
                    d.*,
                    c.name as correspondent_name,
                    dt.label as document_type_name,
                    u.name as owner_name
                FROM documents d
                LEFT JOIN correspondents c ON d.correspondent_id = c.id
                LEFT JOIN document_types dt ON d.document_type_id = dt.id
                LEFT JOIN users u ON d.owner_id = u.id
                WHERE d.id = ?
            ");
            $stmt->execute([$documentId]);
            $document = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($document) {
                // Enrichir avec les dates calculées
                if (!empty($document['created_at'])) {
                    $created = new \DateTime($document['created_at']);
                    $document['created_year'] = $created->format('Y');
                    $document['created_month'] = $created->format('m');
                    $document['created_day'] = $created->format('d');
                }
                
                if (!empty($document['added_at'])) {
                    $added = new \DateTime($document['added_at']);
                    $document['added_year'] = $added->format('Y');
                    $document['added_month'] = $added->format('m');
                    $document['added_day'] = $added->format('d');
                }
            }
            
            return $document ?: null;
        } catch (\Exception $e) {
            error_log("WebhookAction: Erreur récupération document: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Remplace les placeholders dans un template
     */
    private function replacePlaceholders(string $template, array $document): string
    {
        foreach ($this->placeholders as $placeholder => $field) {
            $value = $document[$field] ?? '';
            $template = str_replace($placeholder, $value, $template);
        }
        return $template;
    }
    
    /**
     * Log l'exécution d'un webhook dans webhook_logs
     */
    private function logWebhookExecution(string $url, array $payload, int $httpCode, ?string $response, ?string $error): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                INSERT INTO webhook_logs 
                (webhook_id, event, payload, response_code, response_body, error_message, execution_time_ms, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                null, // webhook_id (null pour workflows)
                'workflow_action',
                json_encode($payload),
                $httpCode,
                $response ? substr($response, 0, 10000) : null,
                $error,
                0 // execution_time_ms (non mesuré ici)
            ]);
        } catch (\Exception $e) {
            // Table peut ne pas exister ou erreur SQL
            error_log("WebhookAction: Erreur logging webhook: " . $e->getMessage());
        }
    }
    
    public function getConfigSchema(): array
    {
        return [
            'url' => [
                'type' => 'string',
                'required' => true,
                'description' => 'URL du webhook',
            ],
            'method' => [
                'type' => 'string',
                'required' => false,
                'default' => 'POST',
                'description' => 'Méthode HTTP (GET, POST, PUT, DELETE)',
                'enum' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH']
            ],
            'payload' => [
                'type' => 'object',
                'required' => false,
                'description' => 'Payload custom (JSON object ou string JSON)',
            ],
            'headers' => [
                'type' => 'object',
                'required' => false,
                'description' => 'Headers HTTP custom (JSON object)',
            ],
            'as_json' => [
                'type' => 'boolean',
                'required' => false,
                'default' => true,
                'description' => 'Envoyer les données en JSON',
            ],
            'use_params' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'description' => 'Utiliser les paramètres GET (pour méthode GET)',
            ],
            'include_document' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'description' => 'Inclure le document en pièce jointe',
            ],
            'timeout' => [
                'type' => 'integer',
                'required' => false,
                'default' => 30,
                'description' => 'Timeout en secondes',
            ],
        ];
    }
}
