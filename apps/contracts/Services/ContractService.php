<?php

declare(strict_types=1);

/**
 * Service contrats — GAP-030.
 *
 * PDO injectable pour les tests hermétiques (SQLite en mémoire).
 * La date limite d'upcoming est calculée en PHP pour rester portable SQLite/MySQL.
 */

namespace KDocs\Apps\Contracts\Services;

use PDO;

class ContractService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Liste les contrats triés par due_date ASC.
     * Filtre optionnel : status.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]           = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        $sql = 'SELECT * FROM contracts';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY due_date ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Crée un contrat et retourne son id.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO contracts
                (document_id, title, counterparty, start_date, due_date, notice_days, status, created_at, updated_at)
             VALUES
                (:document_id, :title, :counterparty, :start_date, :due_date, :notice_days, :status, :created_at, :updated_at)'
        );

        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            ':document_id'  => $data['document_id']  ?? null,
            ':title'        => $data['title'],
            ':counterparty' => $data['counterparty']  ?? null,
            ':start_date'   => $data['start_date']    ?? null,
            ':due_date'     => $data['due_date']      ?? null,
            ':notice_days'  => $data['notice_days']   ?? 30,
            ':status'       => $data['status']        ?? 'active',
            ':created_at'   => $now,
            ':updated_at'   => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Contrats dont l'échéance arrive dans les $withinDays prochains jours
     * (due_date <= aujourd'hui + N jours).
     *
     * Date limite calculée en PHP — portable SQLite/MySQL (pas de DATE_ADD).
     *
     * @return list<array<string,mixed>>
     */
    public function upcoming(int $withinDays = 90): array
    {
        $limit = date('Y-m-d', strtotime("+{$withinDays} days"));

        $stmt = $this->db->prepare(
            'SELECT * FROM contracts
             WHERE due_date IS NOT NULL
               AND due_date <= :limit
             ORDER BY due_date ASC'
        );
        $stmt->execute([':limit' => $limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retourne un contrat par son id, ou null si introuvable.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM contracts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
