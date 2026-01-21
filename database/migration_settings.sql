-- Migration pour la table des paramètres système
-- Permet de configurer base_path et autres paramètres depuis l'interface

CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT,
    `type` VARCHAR(20) DEFAULT 'string' COMMENT 'string, integer, boolean, json',
    `description` TEXT,
    `category` VARCHAR(50) DEFAULT 'general' COMMENT 'general, storage, ocr, ai',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_key (`key`),
    INDEX idx_category (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insérer les paramètres par défaut
INSERT INTO settings (`key`, `value`, `type`, `description`, `category`) VALUES
('storage.base_path', '', 'string', 'Chemin racine des documents (vide = valeur par défaut)', 'storage'),
('storage.allowed_extensions', 'pdf,png,jpg,jpeg,tiff,doc,docx', 'string', 'Extensions de fichiers autorisées (séparées par virgule)', 'storage'),
('ocr.tesseract_path', '', 'string', 'Chemin vers l''exécutable Tesseract (vide = valeur par défaut)', 'ocr'),
('ai.claude_api_key', '', 'string', 'Clé API Claude (vide = utiliser fichier)', 'ai')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
