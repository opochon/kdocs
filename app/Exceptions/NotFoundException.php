<?php

namespace KDocs\Exceptions;

/**
 * Exception for resource not found (HTTP 404 Not Found).
 * Use when a requested resource does not exist.
 */
class NotFoundException extends KDocsException
{
    protected int $httpStatusCode = 404;

    public function __construct(
        string $message = "Resource not found",
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }

    /**
     * Create for entity type and ID
     */
    public static function forEntity(string $entity, int|string $id): self
    {
        return new self(
            "$entity not found",
            0,
            null,
            ['entity' => $entity, 'id' => $id]
        );
    }

    /**
     * Create for document
     */
    public static function document(int $id): self
    {
        return self::forEntity('Document', $id);
    }

    /**
     * Create for user
     */
    public static function user(int $id): self
    {
        return self::forEntity('User', $id);
    }

    /**
     * Create for folder
     */
    public static function folder(int $id): self
    {
        return self::forEntity('Folder', $id);
    }

    /**
     * Create for workflow
     */
    public static function workflow(int $id): self
    {
        return self::forEntity('Workflow', $id);
    }
}
