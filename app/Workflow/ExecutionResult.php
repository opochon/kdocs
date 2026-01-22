<?php
/**
 * K-Docs - ExecutionResult pour Workflow Designer
 * Résultat d'exécution d'un node
 */

namespace KDocs\Workflow;

class ExecutionResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_WAITING = 'waiting';
    
    public function __construct(
        public string $status,
        public string $output = 'default',
        public array $data = [],
        public ?string $error = null,
        public ?int $waitSeconds = null,
        public ?string $waitFor = null
    ) {}
    
    public static function success(array $data = [], string $output = 'default'): self
    {
        return new self(self::STATUS_SUCCESS, $output, $data);
    }
    
    public static function failed(string $error): self
    {
        return new self(self::STATUS_FAILED, 'default', [], $error);
    }
    
    public static function waiting(string $waitFor, ?int $seconds = null): self
    {
        return new self(self::STATUS_WAITING, 'default', [], null, $seconds, $waitFor);
    }
}
