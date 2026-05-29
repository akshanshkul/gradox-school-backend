<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around Laravel Cache that never throws.
 *
 * Goal: reduce DB connection pressure on shared-hosting MySQL by caching
 * read-heavy responses to the file cache (no Redis dependency). Every call
 * is wrapped in try/catch so a broken cache backend gracefully falls back
 * to direct execution — the worst case is "no caching happened", never a
 * 500 error.
 *
 * Toggle the whole layer with env var `CACHE_ENABLED=false` if you ever
 * need to force direct-to-DB temporarily.
 */
class SafeCache
{
    private static function enabled(): bool
    {
        return filter_var(env('CACHE_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
    }

    public static function remember(string $key, int $seconds, callable $callback)
    {
        if (!self::enabled()) {
            return $callback();
        }
        try {
            return Cache::remember($key, $seconds, $callback);
        } catch (\Throwable $e) {
            Log::warning('SafeCache::remember fallback to direct execution', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    public static function forget(string $key): bool
    {
        if (!self::enabled()) {
            return true;
        }
        try {
            return (bool) Cache::forget($key);
        } catch (\Throwable $e) {
            Log::warning('SafeCache::forget failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public static function get(string $key, $default = null)
    {
        if (!self::enabled()) {
            return $default;
        }
        try {
            return Cache::get($key, $default);
        } catch (\Throwable $e) {
            Log::warning('SafeCache::get failed', ['key' => $key, 'error' => $e->getMessage()]);
            return $default;
        }
    }

    public static function put(string $key, $value, int $seconds): bool
    {
        if (!self::enabled()) {
            return true;
        }
        try {
            return (bool) Cache::put($key, $value, $seconds);
        } catch (\Throwable $e) {
            Log::warning('SafeCache::put failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Forget any key that was registered under the given prefix index.
     * Used by the per-school cache invalidation hook.
     */
    public static function forgetPrefix(string $prefix): int
    {
        if (!self::enabled()) {
            return 0;
        }
        try {
            $indexKey = "__index:{$prefix}";
            $index = Cache::get($indexKey, []);
            if (!is_array($index)) {
                return 0;
            }
            $count = 0;
            foreach ($index as $key) {
                if (Cache::forget($key)) {
                    $count++;
                }
            }
            Cache::forget($indexKey);
            return $count;
        } catch (\Throwable $e) {
            Log::warning('SafeCache::forgetPrefix failed', ['prefix' => $prefix, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Register a cache key under a prefix index so we can later forget the whole group.
     */
    public static function indexUnder(string $prefix, string $key, int $seconds): void
    {
        if (!self::enabled()) {
            return;
        }
        try {
            $indexKey = "__index:{$prefix}";
            $existing = Cache::get($indexKey, []);
            if (!is_array($existing)) $existing = [];
            if (!in_array($key, $existing, true)) {
                $existing[] = $key;
                Cache::put($indexKey, $existing, max($seconds, 3600));
            }
        } catch (\Throwable $e) {
            // best effort
        }
    }
}
