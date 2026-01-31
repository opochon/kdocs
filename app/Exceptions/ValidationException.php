<?php

namespace KDocs\Exceptions;

/**
 * Exception for validation errors (HTTP 400 Bad Request).
 * Use when user input fails validation rules.
 */
class ValidationException extends KDocsException
{
    protected int $httpStatusCode = 400;
    protected array $errors = [];

    public function __construct(
        string $message = "Validation failed",
        array $errors = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Create from array of field errors
     */
    public static function fromErrors(array $errors, string $message = "Validation failed"): self
    {
        return new self($message, $errors);
    }

    /**
     * Create for single field
     */
    public static function forField(string $field, string $error): self
    {
        return new self("Validation failed: $error", [$field => $error]);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'errors' => $this->errors,
        ]);
    }
}
