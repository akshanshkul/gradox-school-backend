<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Valkey-backed cache for the PUBLIC landing-page payload only
 * (GET /school/public). Everything else in the app keeps using the
 * default cache driver.
 *
 * Keys are per lookup identifier (slug or custom domain) so a cache hit
 * needs zero DB queries. Any change to a school or its landing data calls
 * forgetSchool(), which drops both identifiers (current + previous values,
 * in case the slug/domain itself changed).
 *
 * Never throws: if Valkey is down we log and fall through to the DB.
 */
class PublicSiteCache
{
    private const STORE = 'valkey';
    private const TTL_SECONDS = 900; // safety net; saves invalidate immediately

    private static function enabled(): bool
    {
        return filter_var(env('PUBLIC_SITE_CACHE', true), FILTER_VALIDATE_BOOLEAN)
            && !empty(config('database.redis.valkey.url'));
    }

    public static function key(string $type, string $value): string
    {
        return 'public_site:' . $type . ':' . strtolower(trim($value));
    }

    public static function remember(string $key, callable $callback)
    {
        if (!self::enabled()) {
            return $callback();
        }
        try {
            $hit = Cache::store(self::STORE)->get($key);
            if ($hit !== null) {
                return $hit;
            }
        } catch (\Throwable $e) {
            Log::warning('PublicSiteCache read failed', ['key' => $key, 'error' => $e->getMessage()]);
            return $callback();
        }

        $value = $callback();
        // Only cache successful payloads (arrays); errors are returned as-is.
        if (is_array($value)) {
            try {
                Cache::store(self::STORE)->put($key, $value, self::TTL_SECONDS);
            } catch (\Throwable $e) {
                Log::warning('PublicSiteCache write failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }
        return $value;
    }

    public static function forgetSchool(?School $school): void
    {
        if (!$school || !self::enabled()) {
            return;
        }
        $keys = [];
        foreach (['slug', 'custom_domain'] as $col) {
            $type = $col === 'slug' ? 'slug' : 'domain';
            foreach ([$school->{$col}, $school->getOriginal($col)] as $v) {
                if (is_string($v) && $v !== '') {
                    $keys[] = self::key($type, $v);
                }
            }
        }
        try {
            foreach (array_unique($keys) as $k) {
                Cache::store(self::STORE)->forget($k);
            }
        } catch (\Throwable $e) {
            Log::warning('PublicSiteCache forget failed', ['school' => $school->id, 'error' => $e->getMessage()]);
        }
    }

    public static function forgetSchoolId(?int $schoolId): void
    {
        if ($schoolId) {
            self::forgetSchool(School::find($schoolId));
        }
    }
}
