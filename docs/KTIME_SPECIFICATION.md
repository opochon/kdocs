# K-Time - Application Timesheet PHP

## Origine

Migration du projet **F:\DATA\DEVELOPPEMENT\TIMETRACKER** (Next.js + Prisma + MySQL) vers PHP natif pour intégration dans l'écosystème K-Docs.

## Vision : Bureau K-Apps

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  K-Apps Bureau                                              [Olivier] [⚙️]  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐          │
│   │  📁     │  │  ⏱️     │  │  📧     │  │  🧾     │  │  📊     │          │
│   │ K-Docs  │  │ K-Time  │  │ K-Mail  │  │K-Invoice│  │ K-Stats │          │
│   │  GED    │  │Timesheet│  │  Mail   │  │Factures │  │ Reports │          │
│   └─────────┘  └─────────┘  └─────────┘  └─────────┘  └─────────┘          │
│                                                                              │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                        Zone de travail                               │   │
│   │                     (App active ici)                                 │   │
│   └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

Si K-Docs (GED) est installé :
- Factures générées → stockées dans K-Docs
- Recherche documents depuis K-Time
- Clients/Correspondants partagés

Si K-Docs n'est pas installé :
- K-Time fonctionne en standalone
- Export PDF local
- Base clients propre

---

## Stack technique

| Composant | Technologie | Raison |
|-----------|-------------|--------|
| Backend | PHP 8.2+ natif | Portable, léger |
| Base de données | MySQL (même que GED) | Cohérence données |
| Frontend | PHP + Tailwind + Alpine.js | SSR rapide |
| PDF | TCPDF ou Dompdf | PHP pur |
| API GED | REST HTTP | Intégration souple |

**PAS DE** : Docker, Node.js, services externes lourds

---

## Schéma base de données

Reprise du schema Prisma existant, adapté pour MySQL direct :

```sql
-- =====================================================
-- K-TIME : Tables principales
-- Préfixe : app_time_
-- =====================================================

-- Clients (peut être sync avec correspondents K-Docs)
CREATE TABLE app_time_clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    kdocs_correspondent_id INT NULL,          -- Lien optionnel K-Docs
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
);

-- Projets
CREATE TABLE app_time_projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    quick_code VARCHAR(10),                   -- Code rapide ex: "A1"
    status ENUM('active', 'completed', 'archived', 'on_hold') DEFAULT 'active',
    budget_hours DECIMAL(10,2),
    budget_amount DECIMAL(12,2),
    rate_override DECIMAL(10,2),              -- NULL = utiliser rate client
    start_date DATE,
    end_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (client_id) REFERENCES app_time_clients(id) ON DELETE CASCADE,
    UNIQUE KEY uk_quick_code (quick_code),
    INDEX idx_status (status)
);

-- Quick Codes (système de saisie rapide)
CREATE TABLE app_time_quick_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL,                -- Ex: "h", "p", "cA1"
    type ENUM('duration', 'supply', 'project', 'description') NOT NULL,
    label VARCHAR(100),                       -- Description affichée
    value VARCHAR(255),                       -- Valeur par défaut
    client_id INT,                            -- Lien optionnel
    project_id INT,                           -- Lien optionnel
    supply_id INT,                            -- Lien optionnel
    is_active BOOLEAN DEFAULT TRUE,
    
    FOREIGN KEY (client_id) REFERENCES app_time_clients(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES app_time_projects(id) ON DELETE SET NULL,
    UNIQUE KEY uk_code (code),
    INDEX idx_type (type)
);

-- Fournitures / Articles
CREATE TABLE app_time_supplies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    quick_code VARCHAR(10),                   -- Ex: "AA", "AB"
    name VARCHAR(255) NOT NULL,
    description TEXT,
    supplier VARCHAR(255),
    sku VARCHAR(100),                         -- Référence fournisseur
    unit VARCHAR(20) DEFAULT 'pce',           -- pce, h, kg, l, m
    purchase_price DECIMAL(10,2),
    sell_price DECIMAL(10,2),
    margin_percent DECIMAL(5,2),
    quantity_in_stock DECIMAL(10,2) DEFAULT 0,
    auto_decrement BOOLEAN DEFAULT TRUE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_quick_code (quick_code),
    INDEX idx_active (is_active)
);

-- Entrées de temps
CREATE TABLE app_time_entries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,                     -- Lien users K-Docs ou local
    client_id INT,
    project_id INT,
    entry_date DATE NOT NULL,
    
    -- Durée (3 méthodes de saisie)
    duration DECIMAL(5,2),                    -- En heures décimales (2.5 = 2h30)
    start_time TIME,                          -- Heure début (optionnel)
    end_time TIME,                            -- Heure fin (optionnel)
    break_minutes INT DEFAULT 0,              -- Pause en minutes
    
    -- Détails
    description TEXT,
    quick_input VARCHAR(255),                 -- Saisie brute ex: "2.5ha pAA2"
    
    -- Facturation
    rate DECIMAL(10,2),                       -- Taux appliqué
    amount DECIMAL(12,2),                     -- Montant calculé
    billable BOOLEAN DEFAULT TRUE,
    billed BOOLEAN DEFAULT FALSE,
    invoice_id INT,                           -- Lien facture générée
    
    -- Timer
    timer_started_at TIMESTAMP NULL,          -- Si chrono actif
    timer_accumulated INT DEFAULT 0,          -- Secondes accumulées
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (client_id) REFERENCES app_time_clients(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES app_time_projects(id) ON DELETE SET NULL,
    INDEX idx_user_date (user_id, entry_date),
    INDEX idx_billable (billable, billed),
    INDEX idx_invoice (invoice_id)
);

-- Lignes de fournitures (attachées aux entrées)
CREATE TABLE app_time_entry_supplies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    entry_id INT NOT NULL,
    supply_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2),                 -- Prix au moment de la saisie
    total_price DECIMAL(12,2),
    note VARCHAR(255),
    
    FOREIGN KEY (entry_id) REFERENCES app_time_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (supply_id) REFERENCES app_time_supplies(id) ON DELETE RESTRICT
);

-- Timers actifs (persistance)
CREATE TABLE app_time_timers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    client_id INT,
    project_id INT,
    description VARCHAR(255),
    started_at TIMESTAMP NOT NULL,
    accumulated_seconds INT DEFAULT 0,        -- Pour pause/reprise
    is_paused BOOLEAN DEFAULT FALSE,
    paused_at TIMESTAMP NULL,
    
    FOREIGN KEY (client_id) REFERENCES app_time_clients(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES app_time_projects(id) ON DELETE SET NULL,
    INDEX idx_user (user_id)
);

-- =====================================================
-- MODE PLANIFIÉ : Équipes et planning
-- =====================================================

-- Équipes
CREATE TABLE app_time_teams (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    leader_user_id INT,                       -- Chef d'équipe
    color VARCHAR(7) DEFAULT '#3B82F6',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Membres d'équipe
CREATE TABLE app_time_team_members (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('leader', 'member') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (team_id) REFERENCES app_time_teams(id) ON DELETE CASCADE,
    UNIQUE KEY uk_team_user (team_id, user_id)
);

-- Tâches planifiées (fiches de travail)
CREATE TABLE app_time_scheduled_tasks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    project_id INT,
    assigned_team_id INT,
    assigned_user_id INT,                     -- OU utilisateur spécifique
    
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
    
    -- Fournitures prévues
    planned_supplies JSON,                    -- [{supply_id, quantity}]
    
    -- Statut
    status ENUM('draft', 'assigned', 'in_progress', 'completed', 'validated', 'cancelled') DEFAULT 'draft',
    
    -- Validation terrain
    actual_start TIME,
    actual_end TIME,
    actual_hours DECIMAL(5,2),
    actual_supplies JSON,                     -- Consommation réelle
    completion_notes TEXT,
    completed_at TIMESTAMP NULL,
    completed_by INT,
    
    -- PDF généré
    pdf_path VARCHAR(500),
    kdocs_document_id INT,                    -- Si stocké dans K-Docs
    
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (client_id) REFERENCES app_time_clients(id),
    FOREIGN KEY (project_id) REFERENCES app_time_projects(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_team_id) REFERENCES app_time_teams(id) ON DELETE SET NULL,
    INDEX idx_date (scheduled_date),
    INDEX idx_status (status),
    INDEX idx_assigned (assigned_user_id, scheduled_date)
);

-- =====================================================
-- FACTURATION
-- =====================================================

-- Factures générées
CREATE TABLE app_time_invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    project_id INT,                           -- Optionnel, si facture par projet
    
    -- Numérotation
    invoice_number VARCHAR(50) NOT NULL,      -- Ex: "2025-0001"
    reference VARCHAR(100),                   -- Référence libre
    
    -- Dates
    invoice_date DATE NOT NULL,
    due_date DATE,
    period_start DATE,                        -- Période couverte
    period_end DATE,
    
    -- Montants
    subtotal DECIMAL(12,2) NOT NULL,
    discount_percent DECIMAL(5,2) DEFAULT 0,
    discount_amount DECIMAL(12,2) DEFAULT 0,
    subtotal_after_discount DECIMAL(12,2),
    vat_rate DECIMAL(4,2) DEFAULT 8.1,        -- TVA Suisse
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
    kdocs_document_id INT,                    -- Si stocké dans K-Docs
    
    -- Export
    winbiz_invoice_id VARCHAR(50),            -- Si exporté vers WinBiz
    exported_at TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (client_id) REFERENCES app_time_clients(id),
    FOREIGN KEY (project_id) REFERENCES app_time_projects(id) ON DELETE SET NULL,
    UNIQUE KEY uk_invoice_number (invoice_number),
    INDEX idx_status (status),
    INDEX idx_date (invoice_date)
);

-- Lignes de facture
CREATE TABLE app_time_invoice_lines (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    line_order INT DEFAULT 0,
    
    -- Type
    line_type ENUM('time', 'supply', 'expense', 'discount', 'text') NOT NULL,
    
    -- Référence
    time_entry_id INT,                        -- Si ligne temps
    supply_id INT,                            -- Si ligne fourniture
    scheduled_task_id INT,                    -- Si depuis fiche travail
    
    -- Contenu
    description TEXT NOT NULL,
    quantity DECIMAL(10,2),
    unit VARCHAR(20),
    unit_price DECIMAL(10,2),
    total_price DECIMAL(12,2),
    
    FOREIGN KEY (invoice_id) REFERENCES app_time_invoices(id) ON DELETE CASCADE,
    INDEX idx_invoice (invoice_id, line_order)
);

-- =====================================================
-- CONFIGURATION
-- =====================================================

CREATE TABLE app_time_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,                              -- NULL = global
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    
    UNIQUE KEY uk_user_key (user_id, setting_key)
);

-- Settings par défaut à insérer
INSERT INTO app_time_settings (user_id, setting_key, setting_value) VALUES
(NULL, 'default_rate', '150.00'),
(NULL, 'currency', 'CHF'),
(NULL, 'vat_rate', '8.1'),
(NULL, 'invoice_prefix', 'INV-'),
(NULL, 'invoice_next_number', '1'),
(NULL, 'quick_codes_enabled', 'true'),
(NULL, 'timer_auto_round', '5'),              -- Arrondir aux 5 minutes
(NULL, 'work_hours_per_day', '8'),
(NULL, 'kdocs_integration', 'false'),         -- Activer si K-Docs présent
(NULL, 'kdocs_invoice_folder', 'Factures/Émises');
```

---

## Système Quick Codes (saisie rapide)

### Syntaxe

```
[durée][code_projet] [pREF][quantité] [description libre]
```

### Exemples

| Saisie | Interprétation |
|--------|----------------|
| `2.5h` | 2h30 (durée seule) |
| `2.5hA1` | 2h30 sur projet A1 |
| `1h30` | 1h30 (format hh:mm supporté) |
| `pAA2` | 2 unités du produit AA |
| `2.5hA1 pAA2` | 2h30 projet A1 + 2 produits AA |
| `2.5hA1 pAA2 peinture porte` | Idem + description "peinture porte" |
| `2hA1 1.5hB2` | 2h projet A1 ET 1h30 projet B2 (2 lignes) |

### Parser PHP

```php
// apps/timetrack/Services/QuickCodeParser.php

class QuickCodeParser
{
    private array $projectCodes = [];
    private array $supplyCodes = [];
    
    public function parse(string $input): array
    {
        $entries = [];
        $supplies = [];
        $description = '';
        
        // Regex patterns
        $durationPattern = '/(\d+(?:[.,]\d+)?)(h|H)([A-Z][A-Z0-9]*)?/';
        $supplyPattern = '/p([A-Z]{2})(\d+(?:[.,]\d+)?)/';
        
        // Extraire durées + projets
        if (preg_match_all($durationPattern, $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $duration = floatval(str_replace(',', '.', $match[1]));
                $projectCode = $match[3] ?? null;
                
                $entries[] = [
                    'duration' => $duration,
                    'project_code' => $projectCode,
                    'project_id' => $projectCode ? $this->resolveProjectCode($projectCode) : null,
                ];
                
                $input = str_replace($match[0], '', $input);
            }
        }
        
        // Extraire fournitures
        if (preg_match_all($supplyPattern, $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $supplyCode = $match[1];
                $quantity = floatval(str_replace(',', '.', $match[2]));
                
                $supplies[] = [
                    'code' => $supplyCode,
                    'supply_id' => $this->resolveSupplyCode($supplyCode),
                    'quantity' => $quantity,
                ];
                
                $input = str_replace($match[0], '', $input);
            }
        }
        
        // Le reste = description
        $description = trim($input);
        
        // Attacher fournitures et description à chaque entrée
        foreach ($entries as &$entry) {
            $entry['supplies'] = $supplies;
            $entry['description'] = $description;
        }
        
        return $entries;
    }
    
    private function resolveProjectCode(string $code): ?int
    {
        // Query DB pour résoudre le code
        return null;
    }
    
    private function resolveSupplyCode(string $code): ?int
    {
        return null;
    }
}
```

---

## Interface utilisateur

### Vue Timesheet (mode Freelance)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ K-Time                                    Janvier 2025    [◀][▶] [Facturer] │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ⏱️ Timer actif : Dupont SA - Migration     [02:34:12]  [⏸️] [⏹️ Terminer] │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │ Saisie rapide : [2.5hA1 pAA2 peinture porte____________________] [+]   ││
│  │                  ↳ 2h30 projet A1 + 2× fourniture AA                   ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                                                              │
│  ═══════════════════════════════════════════════════════════════════════════│
│  Jeudi 30 janvier 2025                                          Total: 6.25h│
│  ───────────────────────────────────────────────────────────────────────────│
│  │ Quick     │ Client      │ Projet      │ Durée │ Montant │ ✓  │ Actions ││
│  ├───────────┼─────────────┼─────────────┼───────┼─────────┼────┼─────────┤│
│  │ 2.5hA1    │ Dupont SA   │ Migration   │ 2.50h │  375.00 │ ☐  │ ✏️ 🗑️   ││
│  │           │ + pAA2      │             │       │ + 45.00 │    │         ││
│  └───────────┴─────────────┴─────────────┴───────┴─────────┴────┴─────────┘│
│                                                                              │
│  📊 Résumé semaine : 32.5h │ 💰 4'875.00 CHF │ 📋 12 entrées │ ☑ 8 facturées│
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Intégration K-Docs (GED)

### Détection automatique

```php
class KDocsIntegration
{
    private bool $available = false;
    
    public function __construct()
    {
        $this->available = $this->checkKDocsAvailable();
    }
    
    private function checkKDocsAvailable(): bool
    {
        // Vérifier si K-Docs est installé
        if (file_exists(__DIR__ . '/../../../config/kdocs.php')) {
            return true;
        }
        
        try {
            $db = Database::getInstance();
            $result = $db->query("SHOW TABLES LIKE 'documents'");
            return $result->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function isAvailable(): bool
    {
        return $this->available;
    }
    
    public function storeInvoice(string $pdfPath, array $metadata): ?int
    {
        if (!$this->available) return null;
        // Appeler API K-Docs pour stocker la facture
    }
    
    public function syncClients(): array
    {
        if (!$this->available) return [];
        // Synchroniser les clients avec correspondents K-Docs
    }
}
```

---

## Routes

```php
// apps/timetrack/routes.php

$router->group('/time', function($router) {
    // Dashboard
    $router->get('', 'DashboardController@index');
    
    // Entrées de temps
    $router->get('/entries', 'EntryController@index');
    $router->post('/entries', 'EntryController@store');
    $router->post('/entries/quick', 'EntryController@quickCreate');
    
    // Timer
    $router->post('/timer/start', 'TimerController@start');
    $router->post('/timer/stop', 'TimerController@stop');
    
    // Clients / Projets
    $router->get('/clients', 'ClientController@index');
    $router->get('/projects/autocomplete', 'ProjectController@autocomplete');
    
    // Factures
    $router->get('/invoices', 'InvoiceController@index');
    $router->post('/invoices/generate', 'InvoiceController@generate');
    
    // Intégration K-Docs
    $router->get('/kdocs/search', 'KDocsController@search');
    $router->post('/kdocs/sync-clients', 'KDocsController@syncClients');
});
```

---

## Fichiers à créer

```
apps/timetrack/
├── Controllers/
│   ├── DashboardController.php
│   ├── EntryController.php
│   ├── TimerController.php
│   ├── ClientController.php
│   ├── ProjectController.php
│   ├── InvoiceController.php
│   └── KDocsController.php
├── Models/
│   ├── Client.php
│   ├── Project.php
│   ├── Entry.php
│   ├── Timer.php
│   ├── Supply.php
│   └── Invoice.php
├── Services/
│   ├── QuickCodeParser.php
│   ├── TimerService.php
│   ├── InvoiceGenerator.php
│   └── KDocsIntegration.php
├── templates/
│   ├── dashboard.php
│   ├── entries/index.php
│   └── invoices/index.php
├── migrations/
│   └── 001_create_timetrack_tables.sql
├── routes.php
├── config.php
└── README.md
```

---

## Priorités de développement

### Phase 1 : Core (1 semaine)
1. [ ] Structure fichiers
2. [ ] Migrations SQL
3. [ ] CRUD Clients/Projets
4. [ ] Saisie entrées basique

### Phase 2 : Quick Codes (3 jours)
1. [ ] Parser QuickCodeParser
2. [ ] UI saisie rapide
3. [ ] Autocomplete projets

### Phase 3 : Timer (2 jours)
1. [ ] TimerService
2. [ ] Widget timer UI

### Phase 4 : Facturation (1 semaine)
1. [ ] InvoiceGenerator
2. [ ] Template PDF
3. [ ] QR facture suisse

### Phase 5 : Intégration K-Docs (3 jours)
1. [ ] KDocsIntegration service
2. [ ] Stockage factures
3. [ ] Sync clients

---

*Spécification K-Time - 30/01/2026*
*Migration depuis F:\DATA\DEVELOPPEMENT\TIMETRACKER*
