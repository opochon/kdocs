-- Migration Webhooks pour K-Docs
-- Date: 2026-01-21

-- Table des webhooks
CREATE TABLE IF NOT EXISTS webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Nom du webhook',
    url VARCHAR(500) NOT NULL COMMENT 'URL de destination',
    events JSON NOT NULL COMMENT 'Liste des événements à écouter (ex: ["document.created", "document.updated"])',
    secret VARCHAR(255) NOT NULL COMMENT 'Secret pour signature HMAC',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Webhook actif ou non',
    timeout INT DEFAULT 30 COMMENT 'Timeout en secondes pour la requête HTTP',
    retry_count INT DEFAULT 3 COMMENT 'Nombre de tentatives en cas d\'échec',
    last_triggered_at TIMESTAMP NULL COMMENT 'Dernière fois que le webhook a été déclenché',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des logs d'exécution des webhooks
CREATE TABLE IF NOT EXISTS webhook_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT NOT NULL,
    event VARCHAR(100) NOT NULL COMMENT 'Événement déclenché',
    payload JSON NOT NULL COMMENT 'Données envoyées',
    response_code INT NULL COMMENT 'Code HTTP de la réponse',
    response_body TEXT NULL COMMENT 'Corps de la réponse',
    error_message TEXT NULL COMMENT 'Message d\'erreur si échec',
    execution_time_ms INT NULL COMMENT 'Temps d\'exécution en millisecondes',
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE,
    INDEX idx_webhook_id (webhook_id),
    INDEX idx_event (event),
    INDEX idx_executed_at (executed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
