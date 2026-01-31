<?php

namespace KDocs\Exceptions;

/**
 * Exception for authorization failures (HTTP 403 Forbidden).
 * Use when user is authenticated but lacks permission.
 */
class AuthorizationException extends KDocsException
{
    protected int $httpStatusCode = 403;

    public function __construct(
        string $message = "Access denied",
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }

    /**
     * Create for missing permission
     */
    public static function missingPermission(string $permission): self
    {
        return new self(
            "You don't have permission to perform this action",
            0,
            null,
            ['required_permission' => $permission]
        );
    }

    /**
     * Create for resource ownership
     */
    public static function notOwner(string $resource): self
    {
        return new self(
            "You don't have access to this $resource",
            0,
            null,
            ['resource' => $resource]
        );
    }

    /**
     * Create for role requirement
     */
    public static function requiresRole(string $role): self
    {
        return new self(
            "This action requires the '$role' role",
            0,
            null,
            ['required_role' => $role]
        );
    }

    /**
     * Create for admin only action
     */
    public static function adminOnly(): self
    {
        return self::requiresRole('admin');
    }
}
