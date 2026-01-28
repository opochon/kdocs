<?php
/**
 * K-Docs - Service Audit
 * Facilite l'enregistrement des actions dans l'audit log
 */

namespace KDocs\Services;

use KDocs\Models\AuditLog;

class AuditService
{
    /**
     * Enregistre une action d'audit avec contexte automatique
     */
    public static function log(
        string $action,
        string $objectType,
        ?int $objectId = null,
        ?string $objectName = null,
        ?array $changes = null,
        ?int $userId = null
    ): void {
        // Récupérer l'IP et le User-Agent depuis les superglobales
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // Si plusieurs IPs (proxy), prendre la première
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ipAddress = trim($ips[0]);
        }
        
        AuditLog::log(
            $action,
            $objectType,
            $objectId,
            $objectName,
            $changes,
            $userId,
            $ipAddress,
            $userAgent
        );
    }

    /**
     * Log une création d'objet
     */
    public static function logCreate(string $objectType, int $objectId, string $objectName, ?int $userId = null): void
    {
        self::log("{$objectType}.created", $objectType, $objectId, $objectName, null, $userId);
    }

    /**
     * Log une mise à jour d'objet
     */
    public static function logUpdate(string $objectType, int $objectId, string $objectName, array $changes, ?int $userId = null): void
    {
        self::log("{$objectType}.updated", $objectType, $objectId, $objectName, $changes, $userId);
    }

    /**
     * Log une suppression d'objet
     */
    public static function logDelete(string $objectType, int $objectId, string $objectName, ?int $userId = null): void
    {
        self::log("{$objectType}.deleted", $objectType, $objectId, $objectName, null, $userId);
    }

    /**
     * Log une restauration d'objet
     */
    public static function logRestore(string $objectType, int $objectId, string $objectName, ?int $userId = null): void
    {
        self::log("{$objectType}.restored", $objectType, $objectId, $objectName, null, $userId);
    }

    /**
     * Log générique (méthode d'instance pour compatibilité FolderService)
     * Format: log('action_name', ['key' => 'value', ...])
     */
    public function logAction(string $action, array $data = []): void
    {
        $userId = $data['user_id'] ?? ($_SESSION['user']['id'] ?? null);
        $objectId = $data['folder_id'] ?? $data['document_id'] ?? $data['id'] ?? null;
        $objectName = $data['path'] ?? $data['name'] ?? $data['new_path'] ?? null;

        // Déterminer le type d'objet depuis l'action
        $objectType = 'folder';
        if (str_starts_with($action, 'document')) {
            $objectType = 'document';
        } elseif (str_starts_with($action, 'user')) {
            $objectType = 'user';
        }

        self::log($action, $objectType, $objectId, $objectName, $data, $userId);
    }
}
