<?php

namespace App\Http\Middleware;

use App\Services\SafeCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-user response cache for read-heavy GET endpoints. Cuts MySQL connection
 * pressure on shared hosting by serving identical responses out of file cache
 * for a short TTL instead of round-tripping the DB on every page load.
 *
 * Only acts on:
 *   - GET requests
 *   - Routes whose path matches the whitelist below
 *   - Authenticated requests (user-scoped cache key)
 *   - Successful 200 JSON responses
 *
 * Cache keys are namespaced by school_id so per-school invalidation through
 * ClearsSchoolCache cleanly drops cached responses when data changes.
 *
 * Skip entirely with header `X-Bypass-Cache: 1` or query param `?fresh=1`.
 */
class CacheUserScopedGet
{
    /**
     * URL substrings that are eligible for caching. We use simple contains
     * checks (instead of full route matching) so the middleware is purely
     * additive — no existing route file needs to change.
     */
    private const CACHEABLE = [
        '/school/bootstrap'           => 200,   // entire bootstrap blob
        '/school/data'                => 200,   // school config
        '/school/configuration'       => 200,  // rarely changes
        '/school/config'              => 200,
        '/school/notifications/counts'=> 200,   // bell badge counter
        '/school/check-availability'  => 200,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->shouldConsider($request)) {
            return $next($request);
        }

        $ttl = $this->cacheableTtl($request);
        if ($ttl === null) {
            return $next($request);
        }

        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $cacheKey = $this->keyFor($request, $user);
        $cached = SafeCache::get($cacheKey);
        if ($cached !== null && is_array($cached) && isset($cached['body'], $cached['status'])) {
            return response($cached['body'], $cached['status'])
                ->header('Content-Type', $cached['content_type'] ?? 'application/json')
                ->header('X-Cache', 'HIT')
                ->header('X-Cache-Key', substr($cacheKey, 0, 60));
        }

        $response = $next($request);

        if ($this->isCacheable($response)) {
            try {
                $payload = [
                    'body' => $response->getContent(),
                    'status' => $response->getStatusCode(),
                    'content_type' => $response->headers->get('Content-Type', 'application/json'),
                ];
                SafeCache::put($cacheKey, $payload, $ttl);

                // Tag this key under per-school index so invalidation can drop the whole group.
                $schoolId = $user->school_id ?? null;
                if ($schoolId) {
                    SafeCache::indexUnder("school_{$schoolId}_url_cache", $cacheKey, $ttl);
                }

                $response->headers->set('X-Cache', 'MISS');
                $response->headers->set('X-Cache-TTL', (string) $ttl);
            } catch (\Throwable $e) {
                // never fail a request because caching couldn't store
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
