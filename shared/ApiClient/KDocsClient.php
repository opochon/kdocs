<?php
/**
 * Client API interne K-Docs
 * Permet aux apps d'interagir avec la GED
 *
 * @package KDocs\Shared\ApiClient
 */

namespace KDocs\Shared\ApiClient;

class KDocsClient
{
    private string $baseUrl;
    private ?string $apiKey;
    private array $headers = [];

    public function __construct(string $baseUrl = '', ?string $apiKey = null)
    {
        $this->baseUrl = $baseUrl ?: $this->detectBaseUrl();
        $this->apiKey = $apiKey;

        if ($this->apiKey) {
            $this->headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
    }

    /**
     * Detecte l'URL de base K-Docs
     */
    private function detectBaseUrl(): string
    {
        // En mode integre, utiliser l'URL courante
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            return $protocol . '://' . $_SERVER['HTTP_HOST'] . '/kdocs';
        }
        return 'http://localhost/kdocs';
    }

    /**
     * Recherche de documents
     */
    public function searchDocuments(string $query, array $filters = []): array
    {
        $params = array_merge(['q' => $query], $filters);
        return $this->get('/api/documents/search', $params);
    }

    /**
     * Recupere un document
     */
    public function getDocument(int $id): ?array
    {
        return $this->get('/api/documents/' . $id);
    }

    /**
     * Upload un document
     */
    public function uploadDocument(string $filePath, array $metadata = []): ?array
    {
        return $this->upload('/api/documents/upload', $filePath, $metadata);
    }

    /**
     * Liste des correspondants
     */
    public function getCorrespondents(): array
    {
        return $this->get('/api/correspondents') ?? [];
    }

    /**
     * Liste des tags
     */
    public function getTags(): array
    {
        return $this->get('/api/tags') ?? [];
    }

    /**
     * Verifie si K-Docs est accessible
     */
    public function isAvailable(): bool
    {
        try {
            $response = $this->get('/api/health');
            return isset($response['status']) && $response['status'] === 'ok';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Requete GET
     */
    protected function get(string $endpoint, array $params = []): ?array
    {
        $url = $this->baseUrl . $endpoint;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        return $this->request('GET', $url);
    }

    /**
     * Requete POST
     */
    protected function post(string $endpoint, array $data = []): ?array
    {
        $url = $this->baseUrl . $endpoint;
        return $this->request('POST', $url, $data);
    }

    /**
     * Upload de fichier
     */
    protected function upload(string $endpoint, string $filePath, array $metadata = []): ?array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);

        $postData = $metadata;
        $postData['files'] = new \CURLFile($filePath);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $headers = $this->headers;
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(
            fn($k, $v) => "$k: $v",
            array_keys($headers),
            $headers
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }

        return null;
    }

    /**
     * Execute une requete HTTP
     */
    protected function request(string $method, string $url, array $data = []): ?array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($data && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $this->headers['Content-Type'] = 'application/json';
        }

        $headers = array_map(
            fn($k, $v) => "$k: $v",
            array_keys($this->headers),
            $this->headers
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }

        return null;
    }
}
