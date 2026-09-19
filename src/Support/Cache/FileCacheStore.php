<?php

namespace sabbajohn\FiscalCore\Support\Cache;

class FileCacheStore
{
    private static $sharedFactory = null;

    private string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir ?? sys_get_temp_dir().'/fiscal-core-cache';
        $this->ensureCacheDirectory();
    }

    /**
     * @return array{value:mixed, stale:bool, created_at:int, age_seconds:int}|null
     */
    public function get(string $key, int $ttl): ?array
    {
        $path = $this->resolvePath($key);

        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['created_at']) || ! array_key_exists('value', $decoded)) {
            return null;
        }

        $createdAt = (int) $decoded['created_at'];
        $isFresh = (time() - $createdAt) <= $ttl;

        return [
            'value' => $decoded['value'],
            'stale' => ! $isFresh,
            'created_at' => $createdAt,
            'age_seconds' => max(0, time() - $createdAt),
        ];
    }

    public function put(string $key, mixed $value): void
    {
        $path = $this->resolvePath($key);
        $payload = [
            'created_at' => time(),
            'value' => $value,
        ];

        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function synchronized(string $key, int $seconds, callable $callback): mixed
    {
        return $callback();
    }

    /** @param list<string> $keys */
    public function synchronizedMany(array $keys, int $seconds, callable $callback): mixed
    {
        return $callback();
    }

    public static function registerSharedFactory(?callable $factory): void
    {
        self::$sharedFactory = $factory;
    }

    public static function shared(string $namespace): self
    {
        if (is_callable(self::$sharedFactory)) {
            $store = call_user_func(self::$sharedFactory, $namespace);
            if ($store instanceof self) {
                return $store;
            }
        }

        return new self;
    }

    private function resolvePath(string $key): string
    {
        return rtrim($this->cacheDir, '/').'/'.sha1($key).'.json';
    }

    private function ensureCacheDirectory(): void
    {
        if (! is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }
}
