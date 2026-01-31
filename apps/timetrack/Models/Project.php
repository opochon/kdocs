<?php
/**
 * K-Time - Model Project
 */

namespace KDocs\Apps\Timetrack\Models;

use KDocs\Core\Database;
use PDO;

class Project
{
    private static ?PDO $db = null;
    private const TABLE = 'app_time_projects';

    public int $id;
    public int $client_id;
    public string $name;
    public ?string $description = null;
    public ?string $quick_code = null;
    public string $status = 'active';
    public ?float $budget_hours = null;
    public ?float $budget_amount = null;
    public ?float $rate_override = null;
    public ?string $start_date = null;
    public ?string $end_date = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    // Joined fields
    public ?string $client_name = null;

    private static function db(): PDO
    {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
        return self::$db;
    }

    public static function all(string $status = 'active'): array
    {
        $sql = "SELECT p.*, c.name as client_name
                FROM " . self::TABLE . " p
                LEFT JOIN app_time_clients c ON p.client_id = c.id
                WHERE p.status = ?
                ORDER BY c.name, p.name";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([$status]);
        return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
    }

    public static function find(int $id): ?self
    {
        $sql = "SELECT p.*, c.name as client_name
                FROM " . self::TABLE . " p
                LEFT JOIN app_time_clients c ON p.client_id = c.id
                WHERE p.id = ?";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([$id]);
        $stmt->setFetchMode(PDO::FETCH_CLASS, self::class);
        return $stmt->fetch() ?: null;
    }

    public static function findByQuickCode(string $code): ?self
    {
        $sql = "SELECT p.*, c.name as client_name
                FROM " . self::TABLE . " p
                LEFT JOIN app_time_clients c ON p.client_id = c.id
                WHERE p.quick_code = ? AND p.status = 'active'";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([$code]);
        $stmt->setFetchMode(PDO::FETCH_CLASS, self::class);
        return $stmt->fetch() ?: null;
    }

    public static function byClient(int $clientId): array
    {
        $stmt = self::db()->prepare(
            "SELECT * FROM " . self::TABLE . " WHERE client_id = ? AND status = 'active' ORDER BY name"
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
    }

    public static function create(array $data): ?self
    {
        $sql = "INSERT INTO " . self::TABLE . "
                (client_id, name, description, quick_code, status, budget_hours, budget_amount, rate_override, start_date, end_date)
                VALUES (:client_id, :name, :description, :quick_code, :status, :budget_hours, :budget_amount, :rate_override, :start_date, :end_date)";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([
            'client_id' => $data['client_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'quick_code' => $data['quick_code'] ?? null,
            'status' => $data['status'] ?? 'active',
            'budget_hours' => $data['budget_hours'] ?? null,
            'budget_amount' => $data['budget_amount'] ?? null,
            'rate_override' => $data['rate_override'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
        ]);

        return self::find((int) self::db()->lastInsertId());
    }

    public function update(array $data): bool
    {
        $sql = "UPDATE " . self::TABLE . " SET
                name = :name,
                description = :description,
                quick_code = :quick_code,
                status = :status,
                budget_hours = :budget_hours,
                budget_amount = :budget_amount,
                rate_override = :rate_override
                WHERE id = :id";

        $stmt = self::db()->prepare($sql);
        return $stmt->execute([
            'id' => $this->id,
            'name' => $data['name'] ?? $this->name,
            'description' => $data['description'] ?? $this->description,
            'quick_code' => $data['quick_code'] ?? $this->quick_code,
            'status' => $data['status'] ?? $this->status,
            'budget_hours' => $data['budget_hours'] ?? $this->budget_hours,
            'budget_amount' => $data['budget_amount'] ?? $this->budget_amount,
            'rate_override' => $data['rate_override'] ?? $this->rate_override,
        ]);
    }

    public function getRate(): float
    {
        if ($this->rate_override !== null) {
            return $this->rate_override;
        }
        $client = Client::find($this->client_id);
        return $client ? $client->default_rate : 150.00;
    }

    public static function autocomplete(string $query): array
    {
        $sql = "SELECT p.id, p.name, p.quick_code, c.name as client_name
                FROM " . self::TABLE . " p
                LEFT JOIN app_time_clients c ON p.client_id = c.id
                WHERE p.status = 'active'
                AND (p.name LIKE ? OR p.quick_code LIKE ? OR c.name LIKE ?)
                ORDER BY c.name, p.name
                LIMIT 20";

        $stmt = self::db()->prepare($sql);
        $like = '%' . $query . '%';
        $stmt->execute([$like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
