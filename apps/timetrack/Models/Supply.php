<?php
/**
 * K-Time - Model Supply (Fourniture)
 */

namespace KDocs\Apps\Timetrack\Models;

use KDocs\Core\Database;
use PDO;

class Supply
{
    private static ?PDO $db = null;
    private const TABLE = 'app_time_supplies';

    public int $id;
    public ?string $quick_code = null;
    public string $name;
    public ?string $description = null;
    public ?string $supplier = null;
    public ?string $sku = null;
    public string $unit = 'pce';
    public ?float $purchase_price = null;
    public ?float $sell_price = null;
    public ?float $margin_percent = null;
    public float $quantity_in_stock = 0;
    public bool $auto_decrement = true;
    public bool $is_active = true;
    public ?string $created_at = null;

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

    public static function findByQuickCode(string $code): ?self
    {
        $stmt = self::db()->prepare(
            "SELECT * FROM " . self::TABLE . " WHERE quick_code = ? AND is_active = 1"
        );
        $stmt->execute([$code]);
        $stmt->setFetchMode(PDO::FETCH_CLASS, self::class);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): ?self
    {
        $sql = "INSERT INTO " . self::TABLE . "
                (quick_code, name, description, supplier, sku, unit, purchase_price, sell_price, margin_percent, quantity_in_stock)
                VALUES (:quick_code, :name, :description, :supplier, :sku, :unit, :purchase_price, :sell_price, :margin_percent, :quantity_in_stock)";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([
            'quick_code' => $data['quick_code'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'supplier' => $data['supplier'] ?? null,
            'sku' => $data['sku'] ?? null,
            'unit' => $data['unit'] ?? 'pce',
            'purchase_price' => $data['purchase_price'] ?? null,
            'sell_price' => $data['sell_price'] ?? null,
            'margin_percent' => $data['margin_percent'] ?? null,
            'quantity_in_stock' => $data['quantity_in_stock'] ?? 0,
        ]);

        return self::find((int) self::db()->lastInsertId());
    }

    public function decrementStock(float $quantity): bool
    {
        if (!$this->auto_decrement) {
            return true;
        }

        $newStock = max(0, $this->quantity_in_stock - $quantity);
        $stmt = self::db()->prepare(
            "UPDATE " . self::TABLE . " SET quantity_in_stock = ? WHERE id = ?"
        );
        return $stmt->execute([$newStock, $this->id]);
    }

    public static function search(string $query): array
    {
        $stmt = self::db()->prepare(
            "SELECT * FROM " . self::TABLE . "
             WHERE (name LIKE ? OR quick_code LIKE ? OR sku LIKE ?) AND is_active = 1
             ORDER BY name LIMIT 20"
        );
        $like = '%' . $query . '%';
        $stmt->execute([$like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
    }
}
