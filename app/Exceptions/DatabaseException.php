<?php

namespace KDocs\Exceptions;

/**
 * Exception for database errors (HTTP 500 Internal Server Error).
 * Use for database connection failures, query errors, constraint violations.
 */
class DatabaseException extends KDocsException
{
    protected int $httpStatusCode = 500;

    public function __construct(
        string $message = "Database error",
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }

    /**
     * Create from PDOException
     */
    public static function fromPDOException(\PDOException $e, string $operation = 'query'): self
    {
        return new self(
            "Database $operation failed",
            (int) $e->getCode(),
            $e,
            ['sql_error' => $e->getMessage()]
        );
    }

    /**
     * Create for connection failure
     */
    public static function connectionFailed(?\Throwable $previous = null): self
    {
        return new self(
            "Failed to connect to database",
            0,
            $previous
        );
    }

    /**
     * Create for constraint violation
     */
    public static function constraintViolation(string $constraint, ?\Throwable $previous = null): self
    {
        return new self(
            "Database constraint violation: $constraint",
            0,
            $previous,
            ['constraint' => $constraint]
        );
    }

    /**
     * Create for duplicate entry
     */
    public static function duplicateEntry(string $field, ?\Throwable $previous = null): self
    {
        return new self(
            "A record with this $field already exists",
            0,
            $previous,
            ['field' => $field]
        );
    }

    /**
     * Create for transaction failure
     */
    public static function transactionFailed(string $reason = '', ?\Throwable $previous = null): self
    {
        $message = "Transaction failed";
        if ($reason) {
            $message .= ": $reason";
        }
        return new self($message, 0, $previous);
    }
}
