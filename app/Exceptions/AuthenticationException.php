<?php

namespace KDocs\Exceptions;

/**
 * Exception for authentication failures (HTTP 401 Unauthorized).
 * Use when authentication is required but not provided or invalid.
 */
class AuthenticationException extends KDocsException
{
    protected int $httpStatusCode = 401;

    public function __construct(
        string $message = "Authentication required",
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }

    /**
     * Create for invalid credentials
     */
    public static function invalidCredentials(): self
    {
        return new self("Invalid username or password");
    }

    /**
     * Create for expired session
     */
    public static function sessionExpired(): self
    {
        return new self("Session has expired, please log in again");
    }

    /**
     * Create for invalid token
     */
    public static function invalidToken(string $tokenType = 'authentication'): self
    {
        return new self("Invalid or expired $tokenType token");
    }

    /**
     * Create for missing authentication
     */
    public static function required(): self
    {
        return new self("Authentication required to access this resource");
    }
}
