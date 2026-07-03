<?php
/**
 * Export de la piste de révision (GAP-022).
 *
 * Produit un tableau structuré de l'historique des événements d'audit,
 * trié chronologiquement (created_at ASC, id ASC), avec filtres optionnels.
 * SQL portable MySQL/SQLite — pas de DATE_SUB ni de fonctions spécifiques.
 */

declare(strict_types=1);

namespace KDocs\Services\Compliance;

use KDocs\Core\Database;

class AuditTrailExportService
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Exporte la piste de révision sous forme de tableau structuré.
     *
     * Filtres supportés :
     *   - object_type  (string)  — filtre sur la colonne object_type
     *   - object_id    (int)     — filtre sur la colonne object_id
     *   - user_id      (int)     — filtre sur la colonne user_id
     *   - from         (string)  — created_at >= valeur (ex. '2026-01-01')
     *   - to           (string)  — created_at <= valeur (ex. '2026-12-31')
     *
     * @param  array<string, mixed> $filters
     * @return array{generated_at: string, filters: array<string,mixed>, count: int, timeline: list<array<string,mixed>>}
     */
    public function export(array $filters = []): array
    {
        [$whereClause, $params] = $this->buildWhere($filters);

        $sql = "SELECT id, user_id, action, object_type, object_id, object_name,
                       changes, ip_address, created_at
                FROM audit_logs
                {$whereClause}
                ORDER BY created_at ASC, id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $timeline = [];
        foreach ($rows as $row) {
            $timeline[] = [
                'at'          => $row['created_at'],
                'user_id'     => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'action'      => $row['action'],
                'object_type' => $row['object_type'],
                'object_id'   => $row['object_id'] !== null ? (int) $row['object_id'] : null,
                'object_name' => $row['object_name'],
                'changes'     => $row['changes'] !== null ? json_decode($row['changes'], true) : null,
                'ip_address'  => $row['ip_address'],
            ];
        }

        return [
            'generated_at' => date('c'),
            'filters'      => $filters,
            'count'        => count($timeline),
            'timeline'     => $timeline,
        ];
    }

    /**
     * Construit la clause WHERE et le tableau de paramètres liés.
     *
     * @param  array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params     = [];

        if (!empty($filters['object_type'])) {
            $conditions[] = 'object_type = ?';
            $params[]     = $filters['object_type'];
        }

        if (!empty($filters['object_id'])) {
            $conditions[] = 'object_id = ?';
            $params[]     = (int) $filters['object_id'];
        }

        if (!empty($filters['user_id'])) {
            $conditions[] = 'user_id = ?';
            $params[]     = (int) $filters['user_id'];
        }

        if (!empty($filters['from'])) {
            $conditions[] = 'created_at >= ?';
            $params[]     = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $conditions[] = 'created_at <= ?';
            $params[]     = $filters['to'];
        }

        $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$whereClause, $params];
    }
}
