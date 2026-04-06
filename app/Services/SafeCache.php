<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SafeCache
{
    /**
     * Resilient remember function that falls back to the file driver if Redis is down.
     */
    public static function remember(string $key, int $seconds, \Closure $callback)
    {
        $hasRedisExtension = extension_loaded('redis');
        $hasPredis = class_exists('\Predis\Client');

        // Only try Redis if the extension or predis library is actually installed
        if ($hasRedisExtension || $hasPredis) {
            try {
                return Cache::store('redis')->remember($key, $seconds, $callback);
            } catch (\Exception $e) {
                Log::warning("Redis store failure, falling back to file for key [{$key}]: " . $e->getMessage());
            }
        }

        // Fallback to File cache (which is safe and always available in Laravel)
        try {
            return Cache::store('file')->remember($key, $seconds, $callback);
        } catch (\Exception $fe) {
            // Absolute fallback - run the callback directly
            return $callback();
        }
    }

    public static function forget(string $key)
    {
        try {
            if (extension_loaded('redis') || class_exists('\Predis\Client')) {
                Cache::store('redis')->forget($key);
            }
            Cache::store('file')->forget($key);
        } catch (\Exception $e) {
            Log::warning("Cache forget failure for key [{$key}]: " . $e->getMessage());
        }
    }
}
