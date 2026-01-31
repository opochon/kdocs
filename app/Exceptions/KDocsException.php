<?php

namespace KDocs\Exceptions;

use Exception;
use Throwable;

/**
 * Base exception class for K-Docs application.
 * All custom exceptions should extend this class.
 */
class KDocsException extends Exception
{
    protected int $httpStatusCode = 500;
    protected array $context = [];

    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get HTTP status code for this exception
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Get additional context data
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set additional context data
     */
    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Add context data
     */
    public function addContext(string $key, mixed $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Convert exception to array for JSON responses
     */
    public function toArray(): array
    {
        return [
            'error' => true,
            'type' => static::class,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'context' => $this->context,
        ];
    }

    /**
     * Log exception with context
     */
    public function log(): void
    {
        $logMessage = sprintf(
            "[%s] %s (code: %d) %s",
            static::class,
            $this->getMessage(),
            $this->getCode(),
            !empty($this->context) ? json_encode($this->context) : ''
        );
        error_log($logMessage);
    }
}
