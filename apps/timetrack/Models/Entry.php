<?php
/**
 * K-Time - Model Entry (Time Entry)
 */

namespace KDocs\Apps\Timetrack\Models;

use KDocs\Core\Database;
use PDO;

class Entry
{
    private static ?PDO $db = null;
    private const TABLE = 'app_time_entries';

    public int $id;
    public int $user_id;
    public ?int $client_id = null;
    public ?int $project_id = null;
    public string $entry_date;
    public ?float $duration = null;
    public ?string $start_time = null;
    public ?string $end_time = null;
    public int $break_minutes = 0;
    public ?string $description = null;
    public ?string $quick_input = null;
    public ?float $rate = null;
    public ?float $amount = null;
    public bool $billable = true;
    public bool $billed = false;
    public ?int $invoice_id = null;
    public ?string $timer_started_at = null;
    public int $timer_accumulated = 0;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    // Joined fields
    public ?string $client_name = null;
    public ?string $project_name = null;
    public ?string $project_quick_code = null;

    private static function db(): PDO
    {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
        return self::$db;
    }

    public static function find(int $id): ?self
    {
        $sql = "SELECT e.*, c.name as client_name, p.name as project_name, p.quick_code as project_quick_code
                FROM " . self::TABLE . " e
                LEFT JOIN app_time_clients c ON e.client_id = c.id
                LEFT JOIN app_time_projects p ON e.project_id = p.id
                WHERE e.id = ?";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([$id]);
        $stmt->setFetchMode(PDO::FETCH_CLASS, self::class);
        return $stmt->fetch() ?: null;
    }

    public static function byDate(int $userId, string $date): array
    {
        $sql = "SELECT e.*, c.name as client_name, p.name as project_name, p.quick_code as project_quick_code
                FROM " . self::TABLE . " e
                LEFT JOIN app_time_clients c ON e.client_id = c.id
                LEFT JOIN app_time_projects p ON e.project_id = p.id
                WHERE e.user_id = ? AND e.entry_date = ?
                ORDER BY e.created_at DESC";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([$userId, $date]);
        return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
    }

    public static function byDateRange(int $userId, string $startDate, string $endDate): array
    {
        $sql = "SELECT e.*, c.name as client_name, p.name as project_name, p.quick_code as project_quick_code
                FROM " . self::TABLE . " e
                LEFT JOIN app_time_clients c ON e.client_id = c.id
                LEFT JOIN app_time_projects p ON e.project_id = p.id
                WHERE e.user_id = ? AND e.entry_date BETWEEN ? AND ?
                ORDER BY e.entry_date DESC, e.created_at DESC";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([$userId, $startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
    }

    public static function create(array $data): ?self
    {
        // Calculate amount if rate and duration provided
        $amount = null;
        if (isset($data['duration']) && isset($data['rate'])) {
            $amount = $data['duration'] * $data['rate'];
        }

        $sql = "INSERT INTO " . self::TABLE . "
                (user_id, client_id, project_id, entry_date, duration, start_time, end_time, break_minutes,
                 description, quick_input, rate, amount, billable)
                VALUES (:user_id, :client_id, :project_id, :entry_date, :duration, :start_time, :end_time, :break_minutes,
                        :description, :quick_input, :rate, :amount, :billable)";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([
            'user_id' => $data['user_id'],
            'client_id' => $data['client_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'entry_date' => $data['entry_date'] ?? date('Y-m-d'),
            'duration' => $data['duration'] ?? null,
            'start_time' => $data['start_time'] ?? null,
            'end_time' => $data['end_time'] ?? null,
            'break_minutes' => $data['break_minutes'] ?? 0,
            'description' => $data['description'] ?? null,
            'quick_input' => $data['quick_input'] ?? null,
            'rate' => $data['rate'] ?? null,
            'amount' => $amount ?? $data['amount'] ?? null,
            'billable' => $data['billable'] ?? true,
        ]);

        return self::find((int) self::db()->lastInsertId());
    }

    public function update(array $data): bool
    {
        $sql = "UPDATE " . self::TABLE . " SET
                client_id = :client_id,
                project_id = :project_id,
                entry_date = :entry_date,
                duration = :duration,
                description = :description,
                rate = :rate,
                amount = :amount,
                billable = :billable
                WHERE id = :id";

        $amount = null;
        $duration = $data['duration'] ?? $this->duration;
        $rate = $data['rate'] ?? $this->rate;
        if ($duration && $rate) {
            $amount = $duration * $rate;
        }

        $stmt = self::db()->prepare($sql);
        return $stmt->execute([
            'id' => $this->id,
            'client_id' => $data['client_id'] ?? $this->client_id,
            'project_id' => $data['project_id'] ?? $this->project_id,
            'entry_date' => $data['entry_date'] ?? $this->entry_date,
            'duration' => $duration,
            'description' => $data['description'] ?? $this->description,
            'rate' => $rate,
            'amount' => $amount,
            'billable' => $data['billable'] ?? $this->billable,
        ]);
    }

    public function delete(): bool
    {
        $stmt = self::db()->prepare("DELETE FROM " . self::TABLE . " WHERE id = ?");
        return $stmt->execute([$this->id]);
    }

    public static function sumByDate(int $userId, string $date): array
    {
        $sql = "SELECT
                    COUNT(*) as count,
                    COALESCE(SUM(duration), 0) as total_hours,
                    COALESCE(SUM(amount), 0) as total_amount
                FROM " . self::TABLE . "
                WHERE user_id = ? AND entry_date = ?";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([$userId, $date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function sumByWeek(int $userId, string $weekStart): array
    {
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

        $sql = "SELECT
                    COUNT(*) as count,
                    COALESCE(SUM(duration), 0) as total_hours,
                    COALESCE(SUM(amount), 0) as total_amount,
                    SUM(CASE WHEN billed = 1 THEN 1 ELSE 0 END) as billed_count
                FROM " . self::TABLE . "
                WHERE user_id = ? AND entry_date BETWEEN ? AND ?";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([$userId, $weekStart, $weekEnd]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function unbilledByClient(int $clientId): array
    {
        $sql = "SELECT e.*, p.name as project_name
                FROM " . self::TABLE . " e
                LEFT JOIN app_time_projects p ON e.project_id = p.id
                WHERE e.client_id = ? AND e.billable = 1 AND e.billed = 0
                ORDER BY e.entry_date ASC";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
    }
}
