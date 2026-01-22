<?php
/**
 * K-Docs - SavedViewRepository
 * Repository pour l'accès aux données saved views
 */

namespace KDocs\Repositories;

use KDocs\Core\Database;
use PDO;

class SavedViewRepository
{
    private Database $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM saved_searches WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        $row['filter_rules'] = $this->getFilterRules($id);
        return $row;
    }
    
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM saved_searches WHERE slug = :slug");
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        $row['filter_rules'] = $this->getFilterRules($row['id']);
        return $row;
    }
    
    public function findAll(?int $ownerId = null): array
    {
        $sql = "SELECT * FROM saved_searches";
        $params = [];
        
        if ($ownerId !== null) {
            $sql .= " WHERE owner_id = :owner_id OR owner_id IS NULL";
            $params['owner_id'] = $ownerId;
        }
        
        $sql .= " ORDER BY name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $views = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['filter_rules'] = $this->getFilterRules($row['id']);
            $views[] = $row;
        }
        
        return $views;
    }
    
    public function findForSidebar(?int $ownerId = null): array
    {
        $sql = "SELECT * FROM saved_searches WHERE show_in_sidebar = 1";
        $params = [];
        
        if ($ownerId !== null) {
            $sql .= " AND (owner_id = :owner_id OR owner_id IS NULL)";
            $params['owner_id'] = $ownerId;
        }
        
        $sql .= " ORDER BY name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $views = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['filter_rules'] = $this->getFilterRules($row['id']);
            $views[] = $row;
        }
        
        return $views;
    }
    
    public function findForDashboard(?int $ownerId = null): array
    {
        $sql = "SELECT * FROM saved_searches WHERE show_on_dashboard = 1";
        $params = [];
        
        if ($ownerId !== null) {
            $sql .= " AND (owner_id = :owner_id OR owner_id IS NULL)";
            $params['owner_id'] = $ownerId;
        }
        
        $sql .= " ORDER BY name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $views = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['filter_rules'] = $this->getFilterRules($row['id']);
            $views[] = $row;
        }
        
        return $views;
    }
    
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO saved_searches (name, slug, sort_field, sort_reverse, show_on_dashboard, show_in_sidebar, owner_id, filter_rules)
            VALUES (:name, :slug, :sort_field, :sort_reverse, :show_on_dashboard, :show_in_sidebar, :owner_id, :filter_rules)
        ");
        
        $slug = $data['slug'] ?? $this->generateSlug($data['name'] ?? 'view');
        $filterRules = !empty($data['filter_rules']) ? json_encode($data['filter_rules']) : null;
        
        $stmt->execute([
            'name' => $data['name'] ?? '',
            'slug' => $slug,
            'sort_field' => $data['sort_field'] ?? 'created_at',
            'sort_reverse' => ($data['sort_reverse'] ?? true) ? 1 : 0,
            'show_on_dashboard' => ($data['show_on_dashboard'] ?? false) ? 1 : 0,
            'show_in_sidebar' => ($data['show_in_sidebar'] ?? false) ? 1 : 0,
            'owner_id' => $data['owner_id'] ?? null,
            'filter_rules' => $filterRules,
        ]);
        
        return (int) $this->db->lastInsertId();
    }
    
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];
        
        if (isset($data['name'])) {
            $fields[] = 'name = :name';
            $params['name'] = $data['name'];
        }
        
        if (isset($data['slug'])) {
            $fields[] = 'slug = :slug';
            $params['slug'] = $data['slug'];
        }
        
        if (isset($data['sort_field'])) {
            $fields[] = 'sort_field = :sort_field';
            $params['sort_field'] = $data['sort_field'];
        }
        
        if (isset($data['sort_reverse'])) {
            $fields[] = 'sort_reverse = :sort_reverse';
            $params['sort_reverse'] = $data['sort_reverse'] ? 1 : 0;
        }
        
        if (isset($data['show_on_dashboard'])) {
            $fields[] = 'show_on_dashboard = :show_on_dashboard';
            $params['show_on_dashboard'] = $data['show_on_dashboard'] ? 1 : 0;
        }
        
        if (isset($data['show_in_sidebar'])) {
            $fields[] = 'show_in_sidebar = :show_in_sidebar';
            $params['show_in_sidebar'] = $data['show_in_sidebar'] ? 1 : 0;
        }
        
        if (isset($data['filter_rules'])) {
            $fields[] = 'filter_rules = :filter_rules';
            $params['filter_rules'] = json_encode($data['filter_rules']);
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $sql = "UPDATE saved_searches SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM saved_searches WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }
    
    private function getFilterRules(int $viewId): array
    {
        // Dans kdocs, filter_rules est stocké en JSON dans la colonne filter_rules
        $stmt = $this->db->prepare("SELECT filter_rules FROM saved_searches WHERE id = :view_id");
        $stmt->execute(['view_id' => $viewId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row || empty($row['filter_rules'])) {
            return [];
        }
        
        $rules = json_decode($row['filter_rules'], true);
        return is_array($rules) ? $rules : [];
    }
    
    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        // Check for uniqueness
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM saved_searches WHERE slug = :slug");
        $stmt->execute(['slug' => $slug]);
        $count = (int) $stmt->fetchColumn();
        
        if ($count > 0) {
            $slug .= '-' . time();
        }
        
        return $slug;
    }
}
