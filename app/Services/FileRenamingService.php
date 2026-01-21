<?php
/**
 * K-Docs - Service FileRenamingService
 * Renommage et organisation automatique des fichiers
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Models\Document;

class FileRenamingService
{
    /**
     * Applique les règles de renommage à un document
     */
    public static function applyRules(int $documentId): bool
    {
        $db = Database::getInstance();
        
        // Récupérer le document
        $document = Document::findById($documentId);
        if (!$document) {
            return false;
        }
        
        // Récupérer les règles actives
        $rules = $db->query("
            SELECT * FROM file_renaming_rules
            WHERE is_active = TRUE
            ORDER BY order_index ASC
        ")->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($rules as $rule) {
            // Vérifier si la règle correspond
            if (self::matchesRule($rule, $document)) {
                // Appliquer le renommage
                $newFilename = self::generateFilename($rule['filename_template'], $document);
                $newFolder = $rule['folder_template'] ? self::generateFolder($rule['folder_template'], $document) : null;
                
                if ($newFilename) {
                    self::renameFile($documentId, $newFilename, $newFolder);
                }
                
                return true; // Une seule règle par document
            }
        }
        
        return false;
    }
    
    /**
     * Vérifie si un document correspond à une règle
     */
    private static function matchesRule(array $rule, array $document): bool
    {
        if (empty($rule['match_filter'])) {
            return true; // Pas de filtre = s'applique à tous
        }
        
        $filter = json_decode($rule['match_filter'], true);
        if (!$filter) {
            return true;
        }
        
        // Vérifier les conditions du filtre
        foreach ($filter as $field => $value) {
            switch ($field) {
                case 'document_type_id':
                    if ($document['document_type_id'] != $value) {
                        return false;
                    }
                    break;
                
                case 'correspondent_id':
                    if ($document['correspondent_id'] != $value) {
                        return false;
                    }
                    break;
                
                case 'has_tags':
                    if (is_array($value)) {
                        $db = Database::getInstance();
                        $stmt = $db->prepare("
                            SELECT COUNT(*) FROM document_tags
                            WHERE document_id = ? AND tag_id IN (" . implode(',', array_fill(0, count($value), '?')) . ")
                        ");
                        $stmt->execute(array_merge([$document['id']], $value));
                        if ($stmt->fetchColumn() === 0) {
                            return false;
                        }
                    }
                    break;
            }
        }
        
        return true;
    }
    
    /**
     * Génère un nom de fichier depuis un template
     */
    private static function generateFilename(string $template, array $document): string
    {
        $replacements = [
            '{title}' => self::sanitizeFilename($document['title'] ?? $document['original_filename'] ?? 'document'),
            '{correspondent}' => self::getCorrespondentName($document['correspondent_id'] ?? null),
            '{document_type}' => self::getDocumentTypeName($document['document_type_id'] ?? null),
            '{date}' => $document['document_date'] ? date('Y-m-d', strtotime($document['document_date'])) : date('Y-m-d'),
            '{year}' => $document['document_date'] ? date('Y', strtotime($document['document_date'])) : date('Y'),
            '{month}' => $document['document_date'] ? date('m', strtotime($document['document_date'])) : date('m'),
            '{day}' => $document['document_date'] ? date('d', strtotime($document['document_date'])) : date('d'),
            '{asn}' => $document['asn'] ?? '',
            '{id}' => $document['id'],
            '{original_filename}' => pathinfo($document['original_filename'] ?? 'document', PATHINFO_FILENAME)
        ];
        
        $filename = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // Ajouter l'extension originale
        $extension = pathinfo($document['original_filename'] ?? 'document.pdf', PATHINFO_EXTENSION);
        if ($extension) {
            $filename .= '.' . $extension;
        }
        
        return $filename;
    }
    
    /**
     * Génère un chemin de dossier depuis un template
     */
    private static function generateFolder(string $template, array $document): string
    {
        $replacements = [
            '{year}' => $document['document_date'] ? date('Y', strtotime($document['document_date'])) : date('Y'),
            '{month}' => $document['document_date'] ? date('m', strtotime($document['document_date'])) : date('m'),
            '{correspondent}' => self::sanitizeFilename(self::getCorrespondentName($document['correspondent_id'] ?? null)),
            '{document_type}' => self::sanitizeFilename(self::getDocumentTypeName($document['document_type_id'] ?? null))
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
    
    /**
     * Renomme un fichier
     */
    private static function renameFile(int $documentId, string $newFilename, ?string $newFolder): void
    {
        $db = Database::getInstance();
        $document = Document::findById($documentId);
        
        if (!$document || !file_exists($document['file_path'])) {
            return;
        }
        
        $basePath = \KDocs\Core\Config::get('storage.base_path', 'C:\\wamp64\\www\\kdocs\\storage\\documents');
        
        // Créer le dossier si nécessaire
        if ($newFolder) {
            $targetDir = $basePath . DIRECTORY_SEPARATOR . $newFolder;
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $newPath = $targetDir . DIRECTORY_SEPARATOR . $newFilename;
        } else {
            $newPath = dirname($document['file_path']) . DIRECTORY_SEPARATOR . $newFilename;
        }
        
        // Renommer le fichier
        if (rename($document['file_path'], $newPath)) {
            // Mettre à jour la base de données
            $db->prepare("UPDATE documents SET filename = ?, file_path = ? WHERE id = ?")
               ->execute([$newFilename, $newPath, $documentId]);
        }
    }
    
    /**
     * Nettoie un nom de fichier
     */
    private static function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
        $filename = preg_replace('/_+/', '_', $filename);
        return trim($filename, '_');
    }
    
    /**
     * Récupère le nom d'un correspondant
     */
    private static function getCorrespondentName(?int $correspondentId): string
    {
        if (!$correspondentId) {
            return '';
        }
        
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT name FROM correspondents WHERE id = ?");
        $stmt->execute([$correspondentId]);
        return $stmt->fetchColumn() ?: '';
    }
    
    /**
     * Récupère le nom d'un type de document
     */
    private static function getDocumentTypeName(?int $typeId): string
    {
        if (!$typeId) {
            return '';
        }
        
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT label FROM document_types WHERE id = ?");
        $stmt->execute([$typeId]);
        return $stmt->fetchColumn() ?: '';
    }
}
