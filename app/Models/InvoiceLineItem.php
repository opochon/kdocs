<?php
/**
 * K-Docs - Modèle Invoice Line Item
 * Lignes de facture extraites par IA
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class InvoiceLineItem
{
    /**
     * Récupère toutes les lignes d'un document
     */
    public static function getForDocument(int $documentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT * FROM invoice_line_items
            WHERE document_id = ?
            ORDER BY line_number
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }

    /**
     * Récupère une ligne par ID
     */
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM invoice_line_items WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Crée une nouvelle ligne
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO invoice_line_items
            (document_id, line_number, quantity, unit, code, description,
             unit_price, discount_percent, tax_rate, tax_amount, line_total,
             compte_comptable, centre_cout, projet, raw_text)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['document_id'],
            $data['line_number'],
            $data['quantity'] ?? null,
            $data['unit'] ?? null,
            $data['code'] ?? null,
            $data['description'],
            $data['unit_price'] ?? null,
            $data['discount_percent'] ?? null,
            $data['tax_rate'] ?? null,
            $data['tax_amount'] ?? null,
            $data['line_total'] ?? null,
            $data['compte_comptable'] ?? null,
            $data['centre_cout'] ?? null,
            $data['projet'] ?? null,
            $data['raw_text'] ?? null
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Met à jour une ligne
     */
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();

        $fields = [];
        $params = [];

        $allowedFields = [
            'quantity', 'unit', 'code', 'description',
            'unit_price', 'discount_percent', 'tax_rate', 'tax_amount', 'line_total',
            'compte_comptable', 'centre_cout', 'projet'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $stmt = $db->prepare("UPDATE invoice_line_items SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($params);
    }

    /**
     * Supprime une ligne
     */
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM invoice_line_items WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Supprime toutes les lignes d'un document
     */
    public static function deleteForDocument(int $documentId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM invoice_line_items WHERE document_id = ?");
        return $stmt->execute([$documentId]);
    }

    /**
     * Crée plusieurs lignes d'un coup (batch insert)
     */
    public static function createBatch(int $documentId, array $lines): array
    {
        $db = Database::getInstance();
        $ids = [];

        foreach ($lines as $index => $line) {
            $line['document_id'] = $documentId;
            $line['line_number'] = $index + 1;
            $ids[] = self::create($line);
        }

        return $ids;
    }

    /**
     * Remplace toutes les lignes d'un document
     */
    public static function replaceForDocument(int $documentId, array $lines): array
    {
        self::deleteForDocument($documentId);
        return self::createBatch($documentId, $lines);
    }

    /**
     * Calcule les totaux d'un document
     */
    public static function calculateTotals(int $documentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT
                COUNT(*) as line_count,
                SUM(COALESCE(line_total, 0)) as subtotal,
                SUM(COALESCE(tax_amount, 0)) as total_tax,
                SUM(COALESCE(line_total, 0)) + SUM(COALESCE(tax_amount, 0)) as grand_total
            FROM invoice_line_items
            WHERE document_id = ?
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetch() ?: ['line_count' => 0, 'subtotal' => 0, 'total_tax' => 0, 'grand_total' => 0];
    }

    /**
     * Réordonne les lignes
     */
    public static function reorder(int $documentId, array $lineIds): bool
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("UPDATE invoice_line_items SET line_number = ? WHERE id = ? AND document_id = ?");

        foreach ($lineIds as $index => $lineId) {
            $stmt->execute([$index + 1, $lineId, $documentId]);
        }

        return true;
    }

    /**
     * Compte le nombre de documents avec des lignes de facture
     */
    public static function countDocumentsWithLines(): int
    {
        $db = Database::getInstance();
        return (int)$db->query("SELECT COUNT(DISTINCT document_id) FROM invoice_line_items")->fetchColumn();
    }
}
