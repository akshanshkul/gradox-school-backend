<?php

namespace App\Http\Middleware;

use App\Services\SafeCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-user response cache for read-heavy GET endpoints with stale-while-error
 * fallback. Cuts MySQL connection pressure on shared hosting by serving
 * identical responses out of file cache instead of round-tripping the DB.
 *
 * Each cached entry is stored with a 24-hour backup TTL so that when the DB
 * becomes unavailable (connection-per-hour cap, "too many connections", etc.)
 * we still have something to serve instead of a 5xx page.
 *
 * Skip with header `X-Bypass-Cache: 1` or `?fresh=1`.
 */
class CacheUserScopedGet
{
    /** URL substring => "fresh" TTL in seconds. Backup TTL is always 24h. */
    private const CACHEABLE = [
        '/school/bootstrap'           => 200,
        '/school/data'                => 200,
        '/school/configuration'       => 200,
        '/school/notifications/counts'=> 200,
        '/school/check-availability'  => 200,
    ];

    private const BACKUP_TTL = 86400; // 24h fallback when DB explodes

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->shouldConsider($request)) {
            return $next($request);
        }

        $freshTtl = $this->cacheableTtl($request);
        if ($freshTtl === null) {
            return $next($request);
        }

        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $cacheKey = $this->keyFor($request, $user);
        $cached = SafeCache::get($cacheKey);

        // Fresh hit — serve directly without touching the DB.
        if ($this->isFresh($cached, $freshTtl)) {
            return $this->respondFromCache($cached, 'HIT', $cacheKey);
        }

        // Run the real handler, but be ready to fall back to a stale cached
        // copy if the DB rejects the connection (1226, 1040, 2002, 2006…).
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            if ($cached && $this->isDbConnectionError($e)) {
                return $this->respondFromCache($cached, 'STALE-FALLBACK', $cacheKey)
                    ->header('X-DB-Error', '1');
            }
            throw $e;
        }

        if ($this->isCacheable($response)) {
            try {
                $payload = [
                    'body' => $response->getContent(),
                    'status' => $response->getStatusCode(),
                    'content_type' => $response->headers->get('Content-Type', 'application/json'),
                    'created_at' => now()->timestamp,
                ];
                SafeCache::put($cacheKey, $payload, self::BACKUP_TTL);

                $schoolId = $user->school_id ?? null;
                if ($schoolId) {
                    SafeCache::indexUnder("school_{$schoolId}_url_cache", $cacheKey, self::BACKUP_TTL);
                }

                $response->headers->set('X-Cache', 'MISS');
                $response->headers->set('X-Cache-TTL', (string) $freshTtl);
            } catch (\Throwable $e) {
                // Never fail a request because caching couldn't store.
            }
        }

        return $response;
    }

    private function shouldConsider(Request $request): bool
    {
        if (strtoupper($request->method()) !== 'GET') return false;
        if ($request->headers->get('X-Bypass-Cache') === '1') return false;
        if ($request->query('fresh') == '1') return false;
        return true;
    }

    private function cacheableTtl(Request $request): ?int
    {
        $path = '/' . ltrim($request->path(), '/');
        foreach (self::CACHEABLE as $needle => $ttl) {
            if (str_contains($path, $needle)) {
                return $ttl;
            }
        }
        return null;
    }

    private function isCacheable(Response $response): bool
    {
        if ($response->getStatusCode() !== 200) return false;
        $contentType = $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'json')) return false;
        if ($response->headers->has('Set-Cookie')) return false;
        return true;
    }

    private function isFresh($cached, int $freshTtl): bool
    {
        if (!is_array($cached) || !isset($cached['body'], $cached['created_at'])) return false;
        return (now()->timestamp - (int) $cached['created_at']) <= $freshTtl;
    }

    private function respondFromCache(array $cached, string $tag, string $cacheKey): Response
    {
        return response($cached['body'], $cached['status'] ?? 200)
            ->header('Content-Type', $cached['content_type'] ?? 'application/json')
            ->header('X-Cache', $tag)
            ->header('X-Cache-Age', (string) (now()->timestamp - ($cached['created_at'] ?? now()->timestamp)))
            ->header('X-Cache-Key', substr($cacheKey, 0, 60));
    }

    /**
     * MySQL / PDO error codes that mean "the DB can't accept this request right now".
     * Treat them all as "serve stale if you have it".
     */
    private function isDbConnectionError(\Throwable $e): bool
    {
        if ($e instanceof \PDOException || $e instanceof \Illuminate\Database\QueryException) {
            $code = (string) ($e->getCode() ?: '');
            $msg = strtolower($e->getMessage());

            $hits = [
                '1226', // ER_USER_LIMIT_REACHED (max_connections_per_hour)
                '1040', // ER_CON_COUNT_ERROR (too many connections)
                '2002', // CR_CONNECTION_ERROR (can't connect)
                '2006', // CR_SERVER_GONE_ERROR (server gone away)
                '2013', // CR_SERVER_LOST
                'HY000',
            ];

            foreach ($hits as $needle) {
                if (str_contains($code, $needle) || str_contains($msg, strtolower($needle))) {
                    return true;
                }
            }

            $phrases = [
                'max_connections_per_hour',
                'has exceeded the',
                'too many connections',
                'connection refused',
                'server has gone away',
                'lost connection',
            ];
            foreach ($phrases as $phrase) {
                if (str_contains($msg, $phrase)) return true;
            }
        }
        return false;
    }

    private function keyFor(Request $request, $user): string
    {
        $schoolId = $user->school_id ?? 'global';
        $path = $request->path();
        $query = $request->query();
        ksort($query);
        $queryHash = empty($query) ? '' : md5(http_build_query($query));
        return "school_{$schoolId}_user_{$user->id}_get_" . md5($path . '|' . $queryHash);
    }
}
