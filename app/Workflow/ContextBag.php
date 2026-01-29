<?php
/**
 * K-Docs - ContextBag pour Workflow Designer
 * Conteneur de données partagées entre les nodes d'un workflow
 * Supporte les variables inter-nœuds et l'interpolation
 */

namespace KDocs\Workflow;

use KDocs\Core\Database;

class ContextBag implements \JsonSerializable
{
    private array $data = [];

    /**
     * Outputs des nœuds: [nodeId => [key => value, ...], ...]
     */
    private array $nodeOutputs = [];

    /**
     * Mapping nom de nœud vers ID pour interpolation par nom
     */
    private array $nodeNameToId = [];

    public function __construct(
        public readonly int $executionId,
        public readonly ?int $documentId,
        public readonly int $workflowId
    ) {}

    // =========================================================================
    // DONNÉES GÉNÉRALES (existant)
    // =========================================================================

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

    // =========================================================================
    // OUTPUTS DE NŒUDS (nouveau)
    // =========================================================================

    /**
     * Définit un output pour un nœud spécifique
     * @param int $nodeId ID du nœud
     * @param string $key Nom de la variable (ex: 'approval_link')
     * @param mixed $value Valeur de la variable
     * @param string $type Type de la valeur (string, integer, boolean, json, url)
     */
    public function setNodeOutput(int $nodeId, string $key, mixed $value, string $type = 'string'): void
    {
        if (!isset($this->nodeOutputs[$nodeId])) {
            $this->nodeOutputs[$nodeId] = [];
        }
        $this->nodeOutputs[$nodeId][$key] = [
            'value' => $value,
            'type' => $type
        ];

        // Persister en base pour pouvoir reprendre le workflow
        $this->persistNodeOutput($nodeId, $key, $value, $type);
    }

    /**
     * Récupère un output d'un nœud spécifique
     * @param int $nodeId ID du nœud
     * @param string $key Nom de la variable
     * @param mixed $default Valeur par défaut
     * @return mixed
     */
    public function getNodeOutput(int $nodeId, string $key, mixed $default = null): mixed
    {
        return $this->nodeOutputs[$nodeId][$key]['value'] ?? $default;
    }

    /**
     * Récupère tous les outputs d'un nœud
     * @param int $nodeId ID du nœud
     * @return array
     */
    public function getNodeOutputs(int $nodeId): array
    {
        $outputs = [];
        foreach ($this->nodeOutputs[$nodeId] ?? [] as $key => $data) {
            $outputs[$key] = $data['value'];
        }
        return $outputs;
    }

    /**
     * Vérifie si un nœud a un output spécifique
     */
    public function hasNodeOutput(int $nodeId, string $key): bool
    {
        return isset($this->nodeOutputs[$nodeId][$key]);
    }

    /**
     * Enregistre le mapping nom -> ID pour un nœud
     */
    public function registerNodeName(int $nodeId, string $nodeName): void
    {
        $this->nodeNameToId[$nodeName] = $nodeId;
    }

    /**
     * Récupère l'ID d'un nœud par son nom
     */
    public function getNodeIdByName(string $nodeName): ?int
    {
        return $this->nodeNameToId[$nodeName] ?? null;
    }

    // =========================================================================
    // INTERPOLATION DE VARIABLES
    // =========================================================================

    /**
     * Interpolation des variables dans une chaîne
     * Supporte:
     *   - {nodeId.key} - Variable d'un nœud par ID
     *   - {nodeName.key} - Variable d'un nœud par nom
     *   - {document.field} - Champ du document
     *   - {key} - Variable simple du contexte
     *
     * @param string $template Template avec placeholders
     * @param array|null $documentData Données du document (optionnel)
     * @return string
     */
    public function interpolate(string $template, ?array $documentData = null): string
    {
        // Pattern pour {xxx.yyy} ou {xxx}
        return preg_replace_callback('/\{([^}]+)\}/', function($matches) use ($documentData) {
            $placeholder = $matches[1];

            // Cas {nodeId.key} ou {nodeName.key}
            if (strpos($placeholder, '.') !== false) {
                [$source, $key] = explode('.', $placeholder, 2);

                // document.field
                if ($source === 'document' && $documentData !== null) {
                    return $documentData[$key] ?? $matches[0];
                }

                // context.key
                if ($source === 'context') {
                    return $this->get($key) ?? $matches[0];
                }

                // Essayer par ID numérique
                if (is_numeric($source)) {
                    $nodeId = (int)$source;
                    if ($this->hasNodeOutput($nodeId, $key)) {
                        return $this->getNodeOutput($nodeId, $key);
                    }
                }

                // Essayer par nom de nœud
                $nodeId = $this->getNodeIdByName($source);
                if ($nodeId !== null && $this->hasNodeOutput($nodeId, $key)) {
                    return $this->getNodeOutput($nodeId, $key);
                }

                // Non trouvé, garder le placeholder
                return $matches[0];
            }

            // Cas simple {key} - chercher dans les données de contexte
            if ($this->has($placeholder)) {
                return $this->get($placeholder);
            }

            // Chercher dans les données du document
            if ($documentData !== null && isset($documentData[$placeholder])) {
                return $documentData[$placeholder];
            }

            // Non trouvé, garder le placeholder
            return $matches[0];
        }, $template);
    }

    /**
     * Liste toutes les variables disponibles pour l'interpolation
     * @return array [['syntax' => '{xxx}', 'source' => 'node|document|context', 'description' => '...'], ...]
     */
    public function getAvailableVariables(): array
    {
        $variables = [];

        // Variables des nœuds
        foreach ($this->nodeOutputs as $nodeId => $outputs) {
            $nodeName = array_search($nodeId, $this->nodeNameToId) ?: "node_$nodeId";
            foreach ($outputs as $key => $data) {
                $variables[] = [
                    'syntax' => "{{$nodeId}.{$key}}",
                    'syntax_by_name' => "{{$nodeName}.{$key}}",
                    'source' => 'node',
                    'node_id' => $nodeId,
                    'node_name' => $nodeName,
                    'key' => $key,
                    'type' => $data['type'],
                    'current_value' => $data['value']
                ];
            }
        }

        // Variables du contexte
        foreach ($this->data as $key => $value) {
            $variables[] = [
                'syntax' => "{{$key}}",
                'source' => 'context',
                'key' => $key,
                'type' => gettype($value),
                'current_value' => is_scalar($value) ? $value : json_encode($value)
            ];
        }

        return $variables;
    }

    // =========================================================================
    // PERSISTANCE
    // =========================================================================

    /**
     * Persiste un output de nœud en base de données
     */
    private function persistNodeOutput(int $nodeId, string $key, mixed $value, string $type): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                INSERT INTO workflow_node_outputs (execution_id, node_id, output_key, output_value, output_type)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE output_value = VALUES(output_value), output_type = VALUES(output_type)
            ");
            $stmt->execute([
                $this->executionId,
                $nodeId,
                $key,
                is_scalar($value) ? (string)$value : json_encode($value),
                $type
            ]);
        } catch (\Exception $e) {
            error_log("ContextBag::persistNodeOutput error: " . $e->getMessage());
        }
    }

    /**
     * Charge les outputs de nœuds depuis la base de données
     */
    public function loadNodeOutputsFromDb(): void
    {
        try {
            $db = Database::getInstance();

            // Charger les outputs
            $stmt = $db->prepare("
                SELECT wno.node_id, wno.output_key, wno.output_value, wno.output_type
                FROM workflow_node_outputs wno
                WHERE wno.execution_id = ?
            ");
            $stmt->execute([$this->executionId]);

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $nodeId = (int)$row['node_id'];
                if (!isset($this->nodeOutputs[$nodeId])) {
                    $this->nodeOutputs[$nodeId] = [];
                }

                $value = $row['output_value'];
                if ($row['output_type'] === 'json') {
                    $value = json_decode($value, true) ?? $value;
                } elseif ($row['output_type'] === 'integer') {
                    $value = (int)$value;
                } elseif ($row['output_type'] === 'boolean') {
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                }

                $this->nodeOutputs[$nodeId][$row['output_key']] = [
                    'value' => $value,
                    'type' => $row['output_type']
                ];
            }

            // Charger le mapping nom -> ID des nœuds
            $stmt = $db->prepare("
                SELECT wn.id, wn.name
                FROM workflow_nodes wn
                WHERE wn.workflow_id = ?
            ");
            $stmt->execute([$this->workflowId]);

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $this->nodeNameToId[$row['name']] = (int)$row['id'];
            }
        } catch (\Exception $e) {
            error_log("ContextBag::loadNodeOutputsFromDb error: " . $e->getMessage());
        }
    }

    // =========================================================================
    // SÉRIALISATION
    // =========================================================================

    public function toArray(): array
    {
        return [
            'execution_id' => $this->executionId,
            'document_id' => $this->documentId,
            'workflow_id' => $this->workflowId,
            'data' => $this->data,
            'node_outputs' => $this->nodeOutputs,
            'node_name_to_id' => $this->nodeNameToId,
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
        $bag->nodeOutputs = $arr['node_outputs'] ?? [];
        $bag->nodeNameToId = $arr['node_name_to_id'] ?? [];
        return $bag;
    }
}
