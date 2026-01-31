-- =====================================================
-- K-TIME : Migration initiale
-- Prefixe : app_time_
-- =====================================================

-- Clients (peut etre sync avec correspondents K-Docs)
CREATE TABLE IF NOT EXISTS app_time_clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    kdocs_correspondent_id INT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(50),
    address TEXT,
    default_rate DECIMAL(10,2) DEFAULT 150.00,
    currency VARCHAR(3) DEFAULT 'CHF',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_kdocs_link (kdocs_correspondent_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Projets
CREATE TABLE IF NOT EXISTS app_time_projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    quick_code VARCHAR(10),
    status ENUM('active', 'completed', 'archived', 'on_hold') DEFAULT 'active',
    budget_hours DECIMAL(10,2),
    budget_amount DECIMAL(12,2),
    rate_override DECIMAL(10,2),
    start_date DATE,
    end_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (client_id) REFERENCES app_time_clients(id) ON DELETE CASCADE,
    UNIQUE KEY uk_quick_code (quick_code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quick Codes (systeme de saisie rapide)
CREATE TABLE IF NOT EXISTS app_time_quick_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL,
    type ENUM('duration', 'supply', 'project', 'description') NOT NULL,
    label VARCHAR(100),
    value VARCHAR(255),
    client_id INT,
    project_id INT,
    supply_id INT,
    is_active BOOLEAN DEFAULT TRUE,

    FOREIGN KEY (client_id) REFERENCES app_time_clients(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES app_time_projects(id) ON DELETE SET NULL,
    UNIQUE KEY uk_code (code),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fournitures / Articles
CREATE TABLE IF NOT EXISTS app_time_supplies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    quick_code VARCHAR(10),
    name VARCHAR(255) NOT NULL,
    description TEXT,
    supplier VARCHAR(255),
    sku VARCHAR(100),
    unit VARCHAR(20) DEFAULT 'pce',
    purchase_price DECIMAL(10,2),
    sell_price DECIMAL(10,2),
    margin_percent DECIMAL(5,2),
    quantity_in_stock DECIMAL(10,2) DEFAULT 0,
    auto_decrement BOOLEAN DEFAULT TRUE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_quick_code (quick_code),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Entrees de temps
CREATE TABLE IF NOT EXISTS app_time_entries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    client_id INT,
    project_id INT,
    entry_date DATE NOT NULL,

    -- Duree (3 methodes de saisie)
    duration DECIMAL(5,2),
    start_time TIME,
    end_time TIME,
    break_minutes INT DEFAULT 0,

    -- Details
    description TEXT,
    quick_input VARCHAR(255),

    -- Facturation
    rate DECIMAL(10,2),
    amount DECIMAL(12,2),
    billable BOOLEAN DEFAULT TRUE,
    billed BOOLEAN DEFAULT FALSE,
    invoice_id INT,

    -- Timer
    timer_started_at TIMESTAMP NULL,
    timer_accumulated INT DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (client_id) REFERENCES app_time_clients(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES app_time_projects(id) ON DELETE SET NULL,
    INDEX idx_user_date (user_id, entry_date),
    INDEX idx_billable (billable, billed),
    INDEX idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lignes de fournitures (attachees aux entrees)
CREATE TABLE IF NOT EXISTS app_time_entry_supplies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    entry_id INT NOT NULL,
    supply_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2),
    total_price DECIMAL(12,2),
    note VARCHAR(255),

    FOREIGN KEY (entry_id) REFERENCES app_time_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (supply_id) REFERENCES app_time_supplies(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timers actifs (persistance)
CREATE TABLE IF NOT EXISTS app_time_timers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    client_id INT,
    project_id INT,
    description VARCHAR(255),
    started_at TIMESTAMP NOT NULL,
    accumulated_seconds INT DEFAULT 0,
    is_paused BOOLEAN DEFAULT FALSE,
    paused_at TIMESTAMP NULL,

    FOREIGN KEY (client_id) REFERENCES app_time_clients(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES app_time_projects(id) ON DELETE SET NULL,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- MODE PLANIFIE : Equipes et planning
-- =====================================================

-- Equipes
CREATE TABLE IF NOT EXISTS app_time_teams (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    leader_user_id INT,
    color VARCHAR(7) DEFAULT '#3B82F6',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Membres d'equipe
CREATE TABLE IF NOT EXISTS app_time_team_members (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('leader', 'member') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (team_id) REFERENCES app_time_teams(id) ON DELETE CASCADE,
    UNIQUE KEY uk_team_user (team_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taches planifiees (fiches de travail)
CREATE TABLE IF NOT EXISTS app_time_scheduled_tasks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    project_id INT,
    assigned_team_id INT,
    assigned_user_id INT,

    -- Planning
    title VARCHAR(255) NOT NULL,
    description TEXT,
    location VARCHAR(500),
    scheduled_date DATE NOT NULL,
    scheduled_start TIME,
    scheduled_end TIME,
    estimated_hours DECIMAL(5,2),

    -- Allocations
    travel_time_minutes INT DEFAULT 0,
    travel_allowance DECIMAL(10,2) DEFAULT 0,
    meal_allowance DECIMAL(10,2) DEFAULT 0,

    -- Fournitures prevues
    planned_supplies JSON,

    -- Statut
    status ENUM('draft', 'assigned', 'in_progress', 'completed', 'validated', 'cancelled') DEFAULT 'draft',

    -- Validation terrain
    actual_start TIME,
    actual_end TIME,
    actual_hours DECIMAL(5,2),
    actual_supplies JSON,
    completion_notes TEXT,
    completed_at TIMESTAMP NULL,
    completed_by INT,

    -- PDF genere
    pdf_path VARCHAR(500),
    kdocs_document_id INT,

    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (client_id) REFERENCES app_time_clients(id),
    FOREIGN KEY (project_id) REFERENCES app_time_projects(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_team_id) REFERENCES app_time_teams(id) ON DELETE SET NULL,
    INDEX idx_date (scheduled_date),
    INDEX idx_status (status),
    INDEX idx_assigned (assigned_user_id, scheduled_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FACTURATION
-- =====================================================

-- Factures generees
CREATE TABLE IF NOT EXISTS app_time_invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    project_id INT,

    -- Numerotation
    invoice_number VARCHAR(50) NOT NULL,
    reference VARCHAR(100),

    -- Dates
    invoice_date DATE NOT NULL,
    due_date DATE,
    period_start DATE,
    period_end DATE,

    -- Montants
    subtotal DECIMAL(12,2) NOT NULL,
    discount_percent DECIMAL(5,2) DEFAULT 0,
    discount_amount DECIMAL(12,2) DEFAULT 0,
    subtotal_after_discount DECIMAL(12,2),
    vat_rate DECIMAL(4,2) DEFAULT 8.1,
    vat_amount DECIMAL(12,2),
    total DECIMAL(12,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'CHF',

    -- Paiement
    status ENUM('draft', 'sent', 'paid', 'partial', 'overdue', 'cancelled') DEFAULT 'draft',
    paid_amount DECIMAL(12,2) DEFAULT 0,
    paid_at DATE,
    payment_method VARCHAR(50),

    -- Contenu
    header_text TEXT,
    footer_text TEXT,
    notes TEXT,

    -- Fichiers
    pdf_path VARCHAR(500),
    kdocs_document_id INT,

    -- Export
    winbiz_invoice_id VARCHAR(50),
    exported_at TIMESTAMP NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (client_id) REFERENCES app_time_clients(id),
    FOREIGN KEY (project_id) REFERENCES app_time_projects(id) ON DELETE SET NULL,
    UNIQUE KEY uk_invoice_number (invoice_number),
    INDEX idx_status (status),
    INDEX idx_date (invoice_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lignes de facture
CREATE TABLE IF NOT EXISTS app_time_invoice_lines (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    line_order INT DEFAULT 0,

    -- Type
    line_type ENUM('time', 'supply', 'expense', 'discount', 'text') NOT NULL,

    -- Reference
    time_entry_id INT,
    supply_id INT,
    scheduled_task_id INT,

    -- Contenu
    description TEXT NOT NULL,
    quantity DECIMAL(10,2),
    unit VARCHAR(20),
    unit_price DECIMAL(10,2),
    total_price DECIMAL(12,2),

    FOREIGN KEY (invoice_id) REFERENCES app_time_invoices(id) ON DELETE CASCADE,
    INDEX idx_invoice (invoice_id, line_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- CONFIGURATION
-- =====================================================

CREATE TABLE IF NOT EXISTS app_time_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,

    UNIQUE KEY uk_user_key (user_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings par defaut
INSERT INTO app_time_settings (user_id, setting_key, setting_value) VALUES
(NULL, 'default_rate', '150.00'),
(NULL, 'currency', 'CHF'),
(NULL, 'vat_rate', '8.1'),
(NULL, 'invoice_prefix', 'INV-'),
(NULL, 'invoice_next_number', '1'),
(NULL, 'quick_codes_enabled', 'true'),
(NULL, 'timer_auto_round', '5'),
(NULL, 'work_hours_per_day', '8'),
(NULL, 'kdocs_integration', 'false'),
(NULL, 'kdocs_invoice_folder', 'Factures/Emises')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
