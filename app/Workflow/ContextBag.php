<?php
/**
 * K-Docs - ContextBag pour Workflow Designer
 * Conteneur de données partagées entre les nodes d'un workflow
 */

namespace KDocs\Workflow;

class ContextBag implements \JsonSerializable
{
    private array $data = [];
    
    public function __construct(
        public readonly int $executionId,
        public readonly ?int $documentId,
        public readonly int $workflowId
    ) {}
    
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
    
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
    
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }
    
    public function merge(array $data): void
    {
        $this->data = array_merge($this->data, $data);
    }
    
    public function toArray(): array
    {
        return [
            'execution_id' => $this->executionId,
            'document_id' => $this->documentId,
            'workflow_id' => $this->workflowId,
            'data' => $this->data,
        ];
    }
    
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
    
    public static function fromArray(array $arr): self
    {
        $bag = new self($arr['execution_id'], $arr['document_id'] ?? null, $arr['workflow_id']);
        $bag->data = $arr['data'] ?? [];
        return $bag;
    }
}
