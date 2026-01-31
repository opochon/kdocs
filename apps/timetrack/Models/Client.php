<?php
/**
 * K-Time - Model Client
 */

namespace KDocs\Apps\Timetrack\Models;

use KDocs\Core\Database;
use PDO;

class Client
{
    private static ?PDO $db = null;
    private const TABLE = 'app_time_clients';

    public int $id;
    public ?int $kdocs_correspondent_id = null;
    public string $name;
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $address = null;
    public float $default_rate = 150.00;
    public string $currency = 'CHF';
    public bool $is_active = true;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    private static function db(): PDO
    {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
        return self::$db;
    }

    public static function all(bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM " . self::TABLE;
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY name ASC";

        $stmt = self::db()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
    }

    public static function find(int $id): ?self
    {
        $stmt = self::db()->prepare("SELECT * FROM " . self::TABLE . " WHERE id = ?");
        $stmt->execute([$id]);
        $stmt->setFetchMode(PDO::FETCH_CLASS, self::class);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): ?self
    {
        $sql = "INSERT INTO " . self::TABLE . " (name, email, phone, address, default_rate, currency, kdocs_correspondent_id)
                VALUES (:name, :email, :phone, :address, :default_rate, :currency, :kdocs_correspondent_id)";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'default_rate' => $data['default_rate'] ?? 150.00,
            'currency' => $data['currency'] ?? 'CHF',
            'kdocs_correspondent_id' => $data['kdocs_correspondent_id'] ?? null,
        ]);

        return self::find((int) self::db()->lastInsertId());
    }

    public function update(array $data): bool
    {
        $sql = "UPDATE " . self::TABLE . " SET
                name = :name,
                email = :email,
                phone = :phone,
                address = :address,
                default_rate = :default_rate,
                currency = :currency,
                is_active = :is_active
                WHERE id = :id";

        $stmt = self::db()->prepare($sql);
        return $stmt->execute([
            'id' => $this->id,
            'name' => $data['name'] ?? $this->name,
            'email' => $data['email'] ?? $this->email,
            'phone' => $data['phone'] ?? $this->phone,
            'address' => $data['address'] ?? $this->address,
            'default_rate' => $data['default_rate'] ?? $this->default_rate,
            'currency' => $data['currency'] ?? $this->currency,
            'is_active' => $data['is_active'] ?? $this->is_active,
        ]);
    }

    public function delete(): bool
    {
        $stmt = self::db()->prepare("UPDATE " . self::TABLE . " SET is_active = 0 WHERE id = ?");
        return $stmt->execute([$this->id]);
    }

    public function projects(): array
    {
        return Project::byClient($this->id);
    }

    public static function search(string $query): array
    {
        $stmt = self::db()->prepare(
            "SELECT * FROM " . self::TABLE . " WHERE name LIKE ? AND is_active = 1 ORDER BY name LIMIT 20"
        );
        $stmt->execute(['%' . $query . '%']);
        return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
    }
}
