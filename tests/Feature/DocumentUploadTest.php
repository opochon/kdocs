<?php
/**
 * Tests Feature - Upload Document
 */

namespace KDocs\Tests\Feature;

use KDocs\Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    private string $baseUrl = 'http://localhost/kdocs';
    private ?string $authCookie = null;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticate();
    }
    
    private function authenticate(): void
    {
        $ch = curl_init($this->baseUrl . '/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => 'admin',
                'password' => 'admin'
            ]),
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        
        if (preg_match_all('/Set-Cookie:\s*([^;]+)/i', $response, $m)) {
            $this->authCookie = implode('; ', $m[1]);
        }
    }
    
    private function request(string $method, string $path, array $data = [], array $files = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ];
        
        if ($this->authCookie) {
            $opts[CURLOPT_COOKIE] = $this->authCookie;
        }
        
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if (!empty($files)) {
                foreach ($files as $key => $path) {
                    $data[$key] = new \CURLFile($path);
                }
            }
            $opts[CURLOPT_POSTFIELDS] = $data;
        }
        
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
    }
    
    public function testUploadPageAccessible(): void
    {
        if (!$this->authCookie) {
            $this->markTestSkipped('Authentication failed');
        }
        
        $r = $this->request('GET', '/documents/upload');
        $this->assertEquals(200, $r['code']);
    }
    
    public function testApiDocumentsListAccessible(): void
    {
        if (!$this->authCookie) {
            $this->markTestSkipped('Authentication failed');
        }
        
        $r = $this->request('GET', '/api/documents');
        $this->assertEquals(200, $r['code']);
        $this->assertIsArray($r['json']);
    }
    
    public function testUploadPdfFile(): void
    {
        if (!$this->authCookie) {
            $this->markTestSkipped('Authentication failed');
        }
        
        $sampleFile = dirname(__DIR__) . '/samples/test.pdf';
        if (!file_exists($sampleFile)) {
            $this->markTestSkipped('Sample file not found');
        }
        
        $r = $this->request('POST', '/api/documents/upload', [], ['file' => $sampleFile]);
        
        $this->assertContains($r['code'], [200, 201]);
    }
}
