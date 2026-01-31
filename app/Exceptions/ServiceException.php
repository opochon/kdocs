<?php

namespace KDocs\Exceptions;

/**
 * Exception for external service errors (HTTP 502 Bad Gateway).
 * Use when external services (OCR, AI, email, etc.) fail.
 */
class ServiceException extends KDocsException
{
    protected int $httpStatusCode = 502;
    protected string $serviceName = '';

    public function __construct(
        string $message = "External service error",
        string $serviceName = '',
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        $this->serviceName = $serviceName;
        if ($serviceName && !str_contains($message, $serviceName)) {
            $message = "[$serviceName] $message";
        }
        parent::__construct($message, $code, $previous, $context);
    }

    /**
     * Get the service name
     */
    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    /**
     * Create for OCR service failure
     */
    public static function ocrFailed(string $reason = '', ?\Throwable $previous = null): self
    {
        $message = $reason ?: "OCR processing failed";
        return new self($message, 'OCR', 0, $previous);
    }

    /**
     * Create for AI/LLM service failure
     */
    public static function aiFailed(string $reason = '', ?\Throwable $previous = null): self
    {
        $message = $reason ?: "AI service request failed";
        return new self($message, 'AI', 0, $previous);
    }

    /**
     * Create for email service failure
     */
    public static function emailFailed(string $reason = '', ?\Throwable $previous = null): self
    {
        $message = $reason ?: "Failed to send email";
        return new self($message, 'Email', 0, $previous);
    }

    /**
     * Create for thumbnail generation failure
     */
    public static function thumbnailFailed(string $reason = '', ?\Throwable $previous = null): self
    {
        $message = $reason ?: "Thumbnail generation failed";
        return new self($message, 'Thumbnail', 0, $previous);
    }

    /**
     * Create for webhook failure
     */
    public static function webhookFailed(string $url, string $reason = '', ?\Throwable $previous = null): self
    {
        $message = $reason ?: "Webhook request failed";
        return new self($message, 'Webhook', 0, $previous, ['url' => $url]);
    }

    /**
     * Create for timeout
     */
    public static function timeout(string $serviceName, int $timeoutSeconds): self
    {
        return new self(
            "Service timed out after {$timeoutSeconds}s",
            $serviceName,
            0,
            null,
            ['timeout' => $timeoutSeconds]
        );
    }

    /**
     * Create for unavailable service
     */
    public static function unavailable(string $serviceName): self
    {
        return new self(
            "Service is currently unavailable",
            $serviceName
        );
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'service' => $this->serviceName,
        ]);
    }
}
