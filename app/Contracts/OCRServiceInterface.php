<?php
namespace KDocs\Contracts;

interface OCRServiceInterface
{
    /**
     * Extract text from a file (PDF or image)
     */
    public function extractText(string $filePath): ?string;
}
