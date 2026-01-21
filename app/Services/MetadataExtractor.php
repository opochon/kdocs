<?php
namespace KDocs\Services;
use KDocs\Core\Config;

class MetadataExtractor
{
    private ?string $claudeApiKey;
    
    public function __construct()
    {
        $config = Config::load();
        $this->claudeApiKey = $config['ai']['claude_api_key'] ?? ($_ENV['CLAUDE_API_KEY'] ?? null);
        
        // Si pas de clé dans la config, chercher dans le fichier à côté des documents
        if (!$this->claudeApiKey) {
            // Utiliser Config::get pour récupérer base_path (inclut les settings DB)
            $basePath = Config::get('storage.base_path', __DIR__ . '/../../storage/documents');
            $keyFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'claude_api_key.txt';
            if (file_exists($keyFile)) {
                $this->claudeApiKey = trim(file_get_contents($keyFile));
            }
        }
    }
    
    public function extractMetadata(string $text, string $filename): array
    {
        if (!$this->claudeApiKey || empty($text)) {
            return $this->extractBasicMetadata($text, $filename);
        }
        try {
            return $this->extractWithClaude($text, $filename);
        } catch (\Exception $e) {
            return $this->extractBasicMetadata($text, $filename);
        }
    }
    
    private function extractWithClaude(string $text, string $filename): array
    {
        $prompt = "Extrait les métadonnées de ce document:\nFichier: {$filename}\nTexte:\n" . substr($text, 0, 5000) . "\n\nRetourne un JSON avec: title, date (YYYY-MM-DD), amount, correspondent, document_type, tags (array)";
        
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'claude-3-5-sonnet-20241022',
                'max_tokens' => 1000,
                'messages' => [['role' => 'user', 'content' => $prompt]]
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->claudeApiKey,
                'anthropic-version: 2023-06-01'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            $content = $result['content'][0]['text'] ?? '';
            if (preg_match('/\{.*\}/s', $content, $matches)) {
                $metadata = json_decode($matches[0], true);
                if ($metadata) return array_merge($this->extractBasicMetadata($text, $filename), $metadata);
            }
        }
        return $this->extractBasicMetadata($text, $filename);
    }
    
    private function extractBasicMetadata(string $text, string $filename): array
    {
        $metadata = ['title' => pathinfo($filename, PATHINFO_FILENAME), 'date' => null, 'amount' => null, 'correspondent' => null, 'document_type' => null, 'tags' => []];
        if (preg_match('/(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/', $text, $matches)) {
            $date = \DateTime::createFromFormat('d/m/Y', $matches[1]) ?: \DateTime::createFromFormat('Y-m-d', $matches[1]);
            if ($date) $metadata['date'] = $date->format('Y-m-d');
        }
        if (preg_match('/(?:total|montant)[\s:]*([\d\s,\.]+)\s*€/i', $text, $matches)) {
            $metadata['amount'] = (float)str_replace(',', '.', preg_replace('/[^\d,.]/', '', $matches[1]));
        }
        $lowerText = strtolower($text);
        if (strpos($lowerText, 'facture') !== false) $metadata['document_type'] = 'facture';
        elseif (strpos($lowerText, 'contrat') !== false) $metadata['document_type'] = 'contrat';
        return $metadata;
    }
}