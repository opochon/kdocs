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
        
        // Extraction de date améliorée - formats multiples
        $datePatterns = [
            // Format français: "Arrêt du 5 juin 2024", "Contrat signé le 20 décembre 2023", "le 05/06/2024"
            '/(?:arrêt|décision|document|facture|contrat|lettre).*?(?:du|le|en)\s+(\d{1,2})\s+(janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre)\s+(\d{4})/i',
            // Format ISO: "2024-06-05" (à tester en premier pour éviter confusion)
            '/(\d{4}[\/\-\.]\d{1,2}[\/\-\.]\d{1,2})/',
            // Format numérique: "05/06/2024", "05-06-2024", "05.06.2024"
            '/(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/',
        ];
        
        $months = [
            'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4,
            'mai' => 5, 'juin' => 6, 'juillet' => 7, 'août' => 8,
            'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12
        ];
        
        foreach ($datePatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                if (isset($matches[3]) && isset($months[strtolower($matches[2])])) {
                    // Format français avec mois en lettres
                    $day = (int)$matches[1];
                    $month = $months[strtolower($matches[2])];
                    $year = (int)$matches[3];
                    try {
                        $date = new \DateTime("$year-$month-$day");
                        $metadata['date'] = $date->format('Y-m-d');
                        break;
                    } catch (\Exception $e) {
                        continue;
                    }
                } else {
                    // Format numérique - détecter si c'est ISO (YYYY-MM-DD) ou européen (DD-MM-YYYY)
                    $dateStr = $matches[1];
                    // Si commence par 4 chiffres, c'est probablement ISO
                    if (preg_match('/^\d{4}/', $dateStr)) {
                        $formats = ['Y-m-d', 'Y/m/d', 'Y.m.d'];
                    } else {
                        $formats = ['d/m/Y', 'd-m-Y', 'd.Y.Y'];
                    }
                    foreach ($formats as $format) {
                        $date = \DateTime::createFromFormat($format, $dateStr);
                        if ($date && $date->format($format) === $dateStr) {
                            $metadata['date'] = $date->format('Y-m-d');
                            break 2;
                        }
                    }
                }
            }
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