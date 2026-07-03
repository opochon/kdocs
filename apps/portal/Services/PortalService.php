<?php
/**
 * Service portail client — accès en lecture seule (GAP-042).
 *
 * Résout un correspondant par son nom et liste ses documents.
 * PDO injectable pour les tests hermétiques SQLite.
 */

namespace KDocs\Apps\Portal\Services;

use KDocs\Core\Database;

class PortalService
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Retrouve un correspondant par son nom exact.
     *
     * @param string $clientName Nom du correspondant (paramètre URL)
     * @return array<string, mixed>|null null si inconnu
     */
    public function getClientByName(string $clientName): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name FROM correspondents WHERE name = ? LIMIT 1'
        );
        $stmt->execute([$clientName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Liste les documents d'un correspondant (lecture seule).
     *
     * Seuls les champs nécessaires à l'affichage sont retournés.
     * Les documents supprimés (deleted_at IS NOT NULL) sont exclus.
     *
     * @param int $clientId ID du correspondant
     * @return list<array{id: int, title: string, created_at: string}>
     */
    public function getDocumentsForClient(int $clientId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, title, created_at
             FROM documents
             WHERE correspondent_id = ? AND deleted_at IS NULL
             ORDER BY created_at DESC'
        );
        $stmt->execute([$clientId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
