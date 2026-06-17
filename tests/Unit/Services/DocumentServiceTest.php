<?php
/**
 * Tests unitaires DocumentService
 */

namespace KDocs\Tests\Unit\Services;

use KDocs\Tests\TestCase;
use KDocs\Services\DocumentService;

class DocumentServiceTest extends TestCase
{
    public function testServiceInstantiates(): void
    {
        $service = new DocumentService();
        $this->assertInstanceOf(DocumentService::class, $service);
    }
    
    public function testGetAllReturnsArray(): void
    {
        $service = new DocumentService();
        $result = $service->getAll();
        
        $this->assertIsArray($result);
    }
    
    public function testGetByIdReturnsNullForInvalidId(): void
    {
        $service = new DocumentService();
        $result = $service->getById(-1);
        
        $this->assertNull($result);
    }
    
    public function testGetByIdReturnsNullForZero(): void
    {
        $service = new DocumentService();
        $result = $service->getById(0);
        
        $this->assertNull($result);
    }
    
    public function testSearchReturnsArray(): void
    {
        $service = new DocumentService();
        $result = $service->search('test');
        
        $this->assertIsArray($result);
    }
    
    public function testSearchWithEmptyQueryReturnsArray(): void
    {
        $service = new DocumentService();
        $result = $service->search('');
        
        $this->assertIsArray($result);
    }
}
