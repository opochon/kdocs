<?php
/**
 * K-Time - Model Timer
 */

namespace KDocs\Apps\Timetrack\Models;

use KDocs\Core\Database;
use PDO;

class Timer
{
    private static ?PDO $db = null;
    private const TABLE = 'app_time_timers';

    public int $id;
    public int $user_id;
    public ?int $client_id = null;
    public ?int $project_id = null;
    public ?string $description = null;
    public string $started_at;
    public int $accumulated_seconds = 0;
    public bool $is_paused = false;
    public ?string $paused_at = null;

    // Joined fields
    public ?string $client_name = null;
    public ?string $project_name = null;

    private static function db(): PDO
    {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
        return self::$db;
    }

    public static function findActive(int $userId): ?self
    {
        $sql = "SELECT t.*, c.name as client_name, p.name as project_name
                FROM " . self::TABLE . " t
                LEFT JOIN app_time_clients c ON t.client_id = c.id
                LEFT JOIN app_time_projects p ON t.project_id = p.id
                WHERE t.user_id = ?
                LIMIT 1";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([$userId]);
        $stmt->setFetchMode(PDO::FETCH_CLASS, self::class);
        return $stmt->fetch() ?: null;
    }

    public static function start(int $userId, ?int $clientId = null, ?int $projectId = null, ?string $description = null): self
    {
        // Stop any existing timer first
        self::stopAll($userId);

        $sql = "INSERT INTO " . self::TABLE . " (user_id, client_id, project_id, description, started_at)
                VALUES (?, ?, ?, ?, NOW())";

        $stmt = self::db()->prepare($sql);
        $stmt->execute([$userId, $clientId, $projectId, $description]);

        return self::findActive($userId);
    }

    public static function stopAll(int $userId): void
    {
        $stmt = self::db()->prepare("DELETE FROM " . self::TABLE . " WHERE user_id = ?");
        $stmt->execute([$userId]);
    }

    public function pause(): bool
    {
        if ($this->is_paused) {
            return false;
        }

        // Calculate elapsed time since start or last resume
        $elapsed = $this->getElapsedSeconds();

        $sql = "UPDATE " . self::TABLE . " SET
                is_paused = 1,
                paused_at = NOW(),
                accumulated_seconds = ?
                WHERE id = ?";

        $stmt = self::db()->prepare($sql);
        return $stmt->execute([$elapsed, $this->id]);
    }

    public function resume(): bool
    {
        if (!$this->is_paused) {
            return false;
        }

        $sql = "UPDATE " . self::TABLE . " SET
                is_paused = 0,
                paused_at = NULL,
                started_at = NOW()
                WHERE id = ?";

        $stmt = self::db()->prepare($sql);
        return $stmt->execute([$this->id]);
    }

    public function stop(): ?Entry
    {
        $totalSeconds = $this->getElapsedSeconds();
        $hours = round($totalSeconds / 3600, 2);

        // Round to nearest 5 minutes
        $minutes = ($totalSeconds / 60);
        $roundedMinutes = round($minutes / 5) * 5;
        $hours = round($roundedMinutes / 60, 2);

        if ($hours < 0.08) { // Less than 5 minutes
            $this->delete();
            return null;
        }

        // Get rate from project or client
        $rate = 150.00;
        if ($this->project_id) {
            $project = Project::find($this->project_id);
            if ($project) {
                $rate = $project->getRate();
            }
        } elseif ($this->client_id) {
            $client = Client::find($this->client_id);
            if ($client) {
                $rate = $client->default_rate;
            }
        }

        // Create entry
        $entry = Entry::create([
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'project_id' => $this->project_id,
            'entry_date' => date('Y-m-d'),
            'duration' => $hours,
            'description' => $this->description,
            'rate' => $rate,
            'billable' => true,
        ]);

        $this->delete();

        return $entry;
    }

    public function delete(): bool
    {
        $stmt = self::db()->prepare("DELETE FROM " . self::TABLE . " WHERE id = ?");
        return $stmt->execute([$this->id]);
    }

    public function getElapsedSeconds(): int
    {
        if ($this->is_paused) {
            return $this->accumulated_seconds;
        }

        $startTime = strtotime($this->started_at);
        $elapsed = time() - $startTime;

        return $this->accumulated_seconds + $elapsed;
    }

    public function getFormattedDuration(): string
    {
        $seconds = $this->getElapsedSeconds();
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'client_name' => $this->client_name,
            'project_id' => $this->project_id,
            'project_name' => $this->project_name,
            'description' => $this->description,
            'started_at' => $this->started_at,
            'is_paused' => $this->is_paused,
            'elapsed_seconds' => $this->getElapsedSeconds(),
            'formatted_duration' => $this->getFormattedDuration(),
        ];
    }
}
