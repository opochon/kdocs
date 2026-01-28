<?php
/**
 * K-Docs - Cache System
 * Simple file-based cache with TTL support
 */

namespace KDocs\Core;

class Cache
{
    private static ?Cache $instance = null;
    private string $cacheDir;
    private bool $enabled = true;

    private function __construct()
    {
        $this->cacheDir = dirname(__DIR__, 2) . '/storage/cache';

        // Create cache directory if it doesn't exist
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public static function getInstance(): Cache
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Store a value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds (default 5 minutes)
     * @return bool
     */
    public function set(string $key, mixed $value, int $ttl = 300): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time()
        ];

        $file = $this->getFilePath($key);
        $tempFile = $file . '.tmp.' . uniqid();

        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return false;
            }

            // Write to temp file first, then rename (atomic operation)
            if (file_put_contents($tempFile, $json, LOCK_EX) !== false) {
                return rename($tempFile, $file);
            }
            return false;
        } catch (\Exception $e) {
            @unlink($tempFile);
            return false;
        }
    }

    /**
     * Get a value from cache
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->enabled) {
            return $default;
        }

        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return $default;
        }

        try {
            $content = file_get_contents($file);
            if ($content === false) {
                return $default;
            }

            $data = json_decode($content, true);
            if ($data === null) {
                return $default;
            }

            // Check if expired
            if (isset($data['expires']) && $data['expires'] < time()) {
                $this->delete($key);
                return $default;
            }

            return $data['value'] ?? $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * Check if a key exists and is not expired
     *
     * @param string $key Cache key
     * @return bool
     */
    public function has(string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return false;
        }

        try {
            $content = file_get_contents($file);
            if ($content === false) {
                return false;
            }

            $data = json_decode($content, true);
            if ($data === null) {
                return false;
            }

            // Check if expired
            if (isset($data['expires']) && $data['expires'] < time()) {
                $this->delete($key);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete a cached value
     *
     * @param string $key Cache key
     * @return bool
     */
    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            return @unlink($file);
        }

        return true;
    }

    /**
     * Clear all cache or by prefix
     *
     * @param string|null $prefix Optional prefix to clear only matching keys
     * @return int Number of deleted files
     */
    public function clear(?string $prefix = null): int
    {
        $count = 0;
        $pattern = $this->cacheDir . '/*.cache';

        foreach (glob($pattern) as $file) {
            if ($prefix !== null) {
                $filename = basename($file, '.cache');
                if (strpos($filename, $this->hashKey($prefix)) !== 0) {
                    continue;
                }
            }

            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get or set a cached value using a callback
     *
     * @param string $key Cache key
     * @param callable $callback Function to generate value if not cached
     * @param int $ttl Time to live in seconds
     * @return mixed
     */
    public function remember(string $key, callable $callback, int $ttl = 300): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Clean up expired cache files
     *
     * @return int Number of deleted files
     */
    public function cleanup(): int
    {
        $count = 0;
        $pattern = $this->cacheDir . '/*.cache';

        foreach (glob($pattern) as $file) {
            try {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                $data = json_decode($content, true);
                if ($data === null || (isset($data['expires']) && $data['expires'] < time())) {
                    if (@unlink($file)) {
                        $count++;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $count;
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function stats(): array
    {
        $stats = [
            'total_files' => 0,
            'total_size' => 0,
            'expired_files' => 0,
            'valid_files' => 0
        ];

        $pattern = $this->cacheDir . '/*.cache';

        foreach (glob($pattern) as $file) {
            $stats['total_files']++;
            $stats['total_size'] += filesize($file);

            try {
                $content = file_get_contents($file);
                $data = json_decode($content, true);

                if ($data !== null && isset($data['expires'])) {
                    if ($data['expires'] < time()) {
                        $stats['expired_files']++;
                    } else {
                        $stats['valid_files']++;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $stats;
    }

    /**
     * Enable or disable cache
     *
     * @param bool $enabled
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Check if cache is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get cache file path for a key
     *
     * @param string $key
     * @return string
     */
    private function getFilePath(string $key): string
    {
        return $this->cacheDir . '/' . $this->hashKey($key) . '.cache';
    }

    /**
     * Hash a cache key
     *
     * @param string $key
     * @return string
     */
    private function hashKey(string $key): string
    {
        // Use a prefix for better file organization
        $prefix = substr(md5($key), 0, 2);
        return $prefix . '_' . md5($key);
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new \RuntimeException("Cannot unserialize singleton");
    }
}
