<?php
/**
 * K-Docs - Contrôleur Export/Import
 */

namespace KDocs\Controllers;

use KDocs\Models\User;
use KDocs\Core\Auth;
use KDocs\Models\Document;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ExportController
{
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Page d'export/import
     */
    public function index(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('ExportController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        
        $role = $user['role'] ?? (($user['is_admin'] ?? false) ? 'admin' : 'user');
        if ($role !== 'admin' && !($user['is_admin'] ?? false)) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/documents')
                ->withStatus(302);
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/export_import.php', []);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Export/Import - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Export/Import'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Export des documents (JSON)
     */
    public function exportDocuments(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('ExportController::exportDocuments', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        
        $role = $user['role'] ?? (($user['is_admin'] ?? false) ? 'admin' : 'user');
        if ($role !== 'admin' && !($user['is_admin'] ?? false)) {
            return $response->withStatus(403);
        }
        
        $db = Database::getInstance();
        $queryParams = $request->getQueryParams();
        
        // Récupérer les documents
        $sql = "
            SELECT d.*, 
                   dt.label as document_type_label,
                   c.name as correspondent_name,
                   GROUP_CONCAT(t.name) as tags,
                   GROUP_CONCAT(t.id) as tag_ids
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_tags dt_rel ON d.id = dt_rel.document_id
            LEFT JOIN tags t ON dt_rel.tag_id = t.id
            WHERE d.deleted_at IS NULL
            GROUP BY d.id
            ORDER BY d.created_at DESC
        ";
        
        $stmt = $db->query($sql);
        $documents = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Récupérer les métadonnées supplémentaires
        foreach ($documents as &$doc) {
            // Custom fields - Utiliser document_custom_field_values
            $doc['custom_fields'] = [];
            try {
                // Vérifier si la table existe
                $tableCheck = $db->query("SHOW TABLES LIKE 'document_custom_field_values'")->fetch();
                if ($tableCheck) {
                    $customFieldsStmt = $db->prepare("
                        SELECT cf.id, cf.name, cf.field_type as type, cfv.value
                        FROM custom_fields cf
                        LEFT JOIN document_custom_field_values cfv ON cf.id = cfv.custom_field_id AND cfv.document_id = ?
                        WHERE cfv.document_id = ?
                    ");
                    $customFieldsStmt->execute([$doc['id'], $doc['id']]);
                    $doc['custom_fields'] = $customFieldsStmt->fetchAll(\PDO::FETCH_ASSOC);
                }
            } catch (\PDOException $e) {
                // Table n'existe pas, ignorer
                $doc['custom_fields'] = [];
            }
            
            // Notes - Vérifier si la table existe
            $doc['notes'] = [];
            try {
                $tableCheck = $db->query("SHOW TABLES LIKE 'document_notes'")->fetch();
                if ($tableCheck) {
                    $notesStmt = $db->prepare("SELECT * FROM document_notes WHERE document_id = ? ORDER BY created_at DESC");
                    $notesStmt->execute([$doc['id']]);
                    $doc['notes'] = $notesStmt->fetchAll(\PDO::FETCH_ASSOC);
                }
            } catch (\PDOException $e) {
                // Table n'existe pas, ignorer
                $doc['notes'] = [];
            }
            
            // Nettoyer les données sensibles
            unset($doc['file_path']); // Ne pas exporter les chemins absolus
        }
        
        $exportData = [
            'version' => '1.0',
            'exported_at' => date('c'),
            'exported_by' => $user['username'],
            'documents' => $documents,
        ];
        
        $filename = 'kdocs_export_' . date('Y-m-d_His') . '.json';
        
        $response->getBody()->write(json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Export des métadonnées uniquement (sans fichiers)
     */
    public function exportMetadata(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('ExportController::exportMetadata', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        
        $role = $user['role'] ?? (($user['is_admin'] ?? false) ? 'admin' : 'user');
        if ($role !== 'admin' && !($user['is_admin'] ?? false)) {
            return $response->withStatus(403);
        }
        
        $db = Database::getInstance();
        
        // Exporter toutes les métadonnées
        $exportData = [
            'version' => '1.0',
            'exported_at' => date('c'),
            'exported_by' => $user['username'],
        ];
        
        // Récupérer les métadonnées avec gestion d'erreurs
        try {
            $exportData['tags'] = $db->query("SELECT * FROM tags")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $exportData['tags'] = [];
        }
        
        try {
            $exportData['correspondents'] = $db->query("SELECT * FROM correspondents")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $exportData['correspondents'] = [];
        }
        
        try {
            $exportData['document_types'] = $db->query("SELECT * FROM document_types")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $exportData['document_types'] = [];
        }
        
        try {
            $exportData['custom_fields'] = $db->query("SELECT * FROM custom_fields")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $exportData['custom_fields'] = [];
        }
        
        try {
            $exportData['storage_paths'] = $db->query("SELECT * FROM storage_paths")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $exportData['storage_paths'] = [];
        }
        
        try {
            $exportData['workflows'] = $db->query("SELECT * FROM workflows")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $exportData['workflows'] = [];
        }
        
        $filename = 'kdocs_metadata_' . date('Y-m-d_His') . '.json';
        
        $response->getBody()->write(json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Import de documents
     */
    public function import(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('ExportController::import', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        
        $role = $user['role'] ?? (($user['is_admin'] ?? false) ? 'admin' : 'user');
        if ($role !== 'admin' && !($user['is_admin'] ?? false)) {
            return $response->withStatus(403);
        }
        
        $uploadedFiles = $request->getUploadedFiles();
        $uploadedFile = $uploadedFiles['file'] ?? null;
        
        if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/admin/export-import?error=upload_failed')
                ->withStatus(302);
        }
        
        $fileContent = $uploadedFile->getStream()->getContents();
        $importData = json_decode($fileContent, true);
        
        if (!$importData || !isset($importData['documents'])) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/admin/export-import?error=invalid_format')
                ->withStatus(302);
        }
        
        $db = Database::getInstance();
        $imported = 0;
        $skipped = 0;
        
        $db->beginTransaction();
        
        try {
            foreach ($importData['documents'] as $docData) {
                // Vérifier si le document existe déjà (par checksum ou filename)
                $checkStmt = $db->prepare("SELECT id FROM documents WHERE filename = ? OR checksum = ?");
                $checkStmt->execute([
                    $docData['filename'] ?? '',
                    $docData['checksum'] ?? ''
                ]);
                
                if ($checkStmt->fetch()) {
                    $skipped++;
                    continue; // Document déjà présent
                }
                
                // Créer le document (sans fichier physique)
                $insertStmt = $db->prepare("
                    INSERT INTO documents (
                        title, filename, original_filename, file_size, mime_type,
                        document_type_id, correspondent_id, doc_date, amount, currency,
                        content, checksum, created_by, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $insertStmt->execute([
                    $docData['title'] ?? null,
                    $docData['filename'] ?? '',
                    $docData['original_filename'] ?? $docData['filename'] ?? '',
                    $docData['file_size'] ?? 0,
                    $docData['mime_type'] ?? 'application/pdf',
                    $docData['document_type_id'] ?? null,
                    $docData['correspondent_id'] ?? null,
                    $docData['doc_date'] ?? null,
                    $docData['amount'] ?? null,
                    $docData['currency'] ?? 'CHF',
                    $docData['content'] ?? null,
                    $docData['checksum'] ?? null,
                    $user['id'],
                    $docData['created_at'] ?? date('Y-m-d H:i:s'),
                    $docData['updated_at'] ?? date('Y-m-d H:i:s'),
                ]);
                
                $documentId = (int)$db->lastInsertId();
                
                // Importer les tags
                if (!empty($docData['tag_ids'])) {
                    $tagIds = is_array($docData['tag_ids']) ? $docData['tag_ids'] : explode(',', $docData['tag_ids']);
                    foreach ($tagIds as $tagId) {
                        $tagId = (int)$tagId;
                        if ($tagId > 0) {
                            $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")->execute([$documentId, $tagId]);
                        }
                    }
                }
                
                // Importer les custom fields
                if (!empty($docData['custom_fields'])) {
                    foreach ($docData['custom_fields'] as $cfData) {
                        if (!empty($cfData['field_id']) && isset($cfData['value'])) {
                            \KDocs\Models\CustomField::setValue($documentId, $cfData['field_id'], $cfData['value']);
                        }
                    }
                }
                
                $imported++;
            }
            
            $db->commit();
            
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/admin/export-import?success=1&imported=' . $imported . '&skipped=' . $skipped)
                ->withStatus(302);
                
        } catch (\Exception $e) {
            $db->rollBack();
            error_log("Erreur import: " . $e->getMessage());
            
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/admin/export-import?error=import_failed')
                ->withStatus(302);
        }
    }
}
