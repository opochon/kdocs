<?php

declare(strict_types=1);

/**
 * Service RH — GAP-033 (dossier RH digital).
 *
 * PDO injectable pour les tests hermétiques (SQLite en mémoire).
 * getEmployee() retourne la fiche employé enrichie avec les documents
 * groupés par catégorie (jointure LEFT JOIN documents pour les titres).
 */

namespace KDocs\Apps\Rh\Services;

use PDO;

class HrService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Fiche employé complète avec dossier documentaire groupé par catégorie.
     *
     * Structure retournée :
     *   [id, first_name, last_name, email, hired_at, position, ...,
     *    documents => ['contrat' => [...], 'salaire' => [...], ...]]
     *
     * @return array<string,mixed>|null  null si l'employé n'existe pas
     */
    public function getEmployee(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM hr_employees WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($employee === false) {
            return null;
        }

        // Documents groupés par catégorie via jointure avec la table documents.
        $stmt = $this->db->prepare(
            'SELECT hed.category, hed.document_id, d.title AS document_title
             FROM hr_employee_documents hed
             LEFT JOIN documents d ON d.id = hed.document_id
             WHERE hed.employee_id = :id
             ORDER BY hed.category ASC, hed.id ASC'
        );
        $stmt->execute([':id' => $id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byCategory = [];
        foreach ($rows as $row) {
            $cat = (string) $row['category'];
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = [];
            }
            $byCategory[$cat][] = [
                'document_id' => (int) $row['document_id'],
                'title'       => $row['document_title'],
            ];
        }

        $employee['documents'] = $byCategory;

        return $employee;
    }

    /**
     * Liste tous les employés (sans documents).
     *
     * @return list<array<string,mixed>>
     */
    public function listEmployees(): array
    {
        $stmt = $this->db->query('SELECT * FROM hr_employees ORDER BY last_name ASC, first_name ASC');

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Attache un document à un employé dans une catégorie donnée.
     *
     * @return int  id de la ligne créée dans hr_employee_documents
     */
    public function attachDocument(int $employeeId, int $documentId, string $category): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO hr_employee_documents (employee_id, document_id, category, created_at)
             VALUES (:employee_id, :document_id, :category, :created_at)'
        );
        $stmt->execute([
            ':employee_id' => $employeeId,
            ':document_id' => $documentId,
            ':category'    => $category,
            ':created_at'  => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }
}
