-- K-Docs Migration: Snapshots et Versioning
-- Système de snapshots avec delta et versioning des documents

-- Table des snapshots du système
CREATE TABLE IF NOT EXISTS snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    snapshot_type ENUM('manual', 'scheduled', 'pre_operation', 'backup') DEFAULT 'manual',

    -- Statistiques au moment du snapshot
    total_documents INT DEFAULT 0,
    total_size_bytes BIGINT DEFAULT 0,
    total_folders INT DEFAULT 0,

    -- Données du snapshot (JSON sérialisé)
    metadata JSON NULL COMMENT 'Configuration et métadonnées au moment du snapshot',

    -- État
    status ENUM('pending', 'in_progress', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,

    -- Timestamps
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,

    INDEX idx_snapshots_type (snapshot_type),
    INDEX idx_snapshots_status (status),
    INDEX idx_snapshots_created (created_at)
);

-- Table des items du snapshot (delta - seulement ce qui a changé)
CREATE TABLE IF NOT EXISTS snapshot_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,

    -- Référence à l'entité
    entity_type ENUM('document', 'folder', 'tag', 'correspondent', 'document_type', 'workflow', 'setting') NOT NULL,
    entity_id INT NOT NULL,

    -- Action au moment du snapshot
    action ENUM('created', 'modified', 'deleted', 'unchanged') DEFAULT 'unchanged',

    -- Données au moment du snapshot (JSON)
    data_snapshot JSON NOT NULL COMMENT 'État complet de l entité',

    -- Pour les documents: hash du fichier pour détecter les changements
    content_hash VARCHAR(64) NULL,

    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_snapshot_items_snapshot (snapshot_id),
    INDEX idx_snapshot_items_entity (entity_type, entity_id),
    FOREIGN KEY (snapshot_id) REFERENCES snapshots(id) ON DELETE CASCADE
);

-- Table des versions de documents
CREATE TABLE IF NOT EXISTS document_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    version_number INT NOT NULL DEFAULT 1,

    -- Fichier de cette version
    filename VARCHAR(255) NOT NULL,
    file_path TEXT NOT NULL,
    file_size BIGINT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    checksum VARCHAR(64) NOT NULL COMMENT 'SHA256 du fichier',

    -- Métadonnées de cette version
    title VARCHAR(255) NULL,
    content_text LONGTEXT NULL COMMENT 'Texte extrait à cette version',

    -- Delta par rapport à la version précédente
    changes_summary TEXT NULL COMMENT 'Résumé des changements',
    delta_size INT NULL COMMENT 'Taille du delta en bytes',

    -- Qui et quand
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    comment TEXT NULL COMMENT 'Commentaire utilisateur sur cette version',

    -- Version actuelle ?
    is_current BOOLEAN DEFAULT FALSE,

    UNIQUE KEY uk_document_version (document_id, version_number),
    INDEX idx_document_versions_document (document_id),
    INDEX idx_document_versions_current (document_id, is_current),
    INDEX idx_document_versions_checksum (checksum)
);

-- Table de comparaison entre versions (cache des diffs)
CREATE TABLE IF NOT EXISTS version_diffs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    from_version INT NOT NULL,
    to_version INT NOT NULL,

    -- Type de diff
    diff_type ENUM('text', 'binary', 'metadata') NOT NULL,

    -- Résultat du diff (JSON ou texte)
    diff_content LONGTEXT NULL,
    diff_stats JSON NULL COMMENT 'Statistiques: lignes ajoutées, supprimées, etc.',

    -- Cache
    computed_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_version_diff (document_id, from_version, to_version, diff_type),
    INDEX idx_version_diffs_document (document_id)
);

-- Ajout des colonnes de version au document
ALTER TABLE documents
ADD COLUMN IF NOT EXISTS current_version INT DEFAULT 1,
ADD COLUMN IF NOT EXISTS version_count INT DEFAULT 1,
ADD COLUMN IF NOT EXISTS last_version_at DATETIME NULL;

-- Trigger pour incrémenter le numéro de version automatiquement
DELIMITER //

CREATE TRIGGER IF NOT EXISTS before_document_version_insert
BEFORE INSERT ON document_versions
FOR EACH ROW
BEGIN
    DECLARE max_version INT;

    -- Récupérer la version max existante
    SELECT COALESCE(MAX(version_number), 0) INTO max_version
    FROM document_versions
    WHERE document_id = NEW.document_id;

    -- Assigner le prochain numéro
    SET NEW.version_number = max_version + 1;
END//

CREATE TRIGGER IF NOT EXISTS after_document_version_insert
AFTER INSERT ON document_versions
FOR EACH ROW
BEGIN
    -- Désactiver is_current sur les anciennes versions
    UPDATE document_versions
    SET is_current = FALSE
    WHERE document_id = NEW.document_id AND id != NEW.id;

    -- Mettre à jour le document
    UPDATE documents
    SET current_version = NEW.version_number,
        version_count = NEW.version_number,
        last_version_at = NEW.created_at,
        updated_at = NEW.created_at
    WHERE id = NEW.document_id;
END//

DELIMITER ;

-- Index pour la recherche sémantique (score de similarité)
CREATE TABLE IF NOT EXISTS semantic_search_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    query_hash VARCHAR(64) NOT NULL COMMENT 'Hash de la requête',
    query_text TEXT NOT NULL,

    -- Résultats cachés
    results JSON NOT NULL COMMENT 'Liste des document_ids avec scores',
    result_count INT NOT NULL,

    -- Paramètres de recherche
    search_params JSON NULL COMMENT 'Filtres, limit, etc.',

    -- Cache
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    hit_count INT DEFAULT 0,

    UNIQUE KEY uk_semantic_cache_query (query_hash),
    INDEX idx_semantic_cache_expires (expires_at)
);

-- Statistiques de recherche sémantique
CREATE TABLE IF NOT EXISTS semantic_search_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    query_text TEXT NOT NULL,

    -- Résultats
    result_count INT NOT NULL,
    top_score DECIMAL(5,4) NULL,
    avg_score DECIMAL(5,4) NULL,

    -- Performance
    search_time_ms INT NULL,
    used_cache BOOLEAN DEFAULT FALSE,

    -- Feedback
    clicked_document_id INT NULL,
    feedback_helpful BOOLEAN NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_semantic_logs_user (user_id),
    INDEX idx_semantic_logs_date (created_at)
);

-- Settings pour les snapshots
INSERT IGNORE INTO settings (`key`, `value`, `type`, `description`) VALUES
('snapshot_auto_enabled', '1', 'boolean', 'Activer les snapshots automatiques'),
('snapshot_auto_interval', '24', 'integer', 'Intervalle entre snapshots auto (heures)'),
('snapshot_retention_days', '90', 'integer', 'Durée de rétention des snapshots (jours)'),
('snapshot_max_count', '30', 'integer', 'Nombre maximum de snapshots à conserver'),
('version_auto_enabled', '1', 'boolean', 'Créer automatiquement une version lors des modifications'),
('version_max_per_document', '50', 'integer', 'Nombre maximum de versions par document'),
('semantic_cache_ttl_minutes', '60', 'integer', 'TTL du cache de recherche sémantique (minutes)');
