<?php

namespace App\Http\Middleware;

use App\Services\SafeCache;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global guard for database availability failures (shared-MySQL
 * connection caps, "server has gone away", etc).
 *
 * When the DB rejects a query because of resource exhaustion, the user
 * normally sees either a 500 stack trace or the raw SQL error string in
 * the UI banner. This middleware converts those into a clean JSON 503
 * with a `Retry-After` header so the front-end can show a friendly
 * "Service busy" message and back off automatically.
 *
 * Also implements a short-lived "circuit breaker" — once the DB is
 * known to be unavailable, subsequent requests skip the DB attempt for
 * a few seconds, reducing further pressure on the cap.
 */
class HandleDatabaseErrors
{
    private const BREAKER_KEY = 'platform:db_breaker_until';
    private const BREAKER_COOLDOWN_SECONDS = 30; // back off for 30s after a failure

    public function handle(Request $request, Closure $next): Response
    {
        // Circuit breaker — if a previous request just hit a DB cap, fail fast.
        $until = SafeCache::get(self::BREAKER_KEY);
        if (is_numeric($until) && now()->timestamp < (int) $until) {
            return $this->busyResponse(((int) $until) - now()->timestamp, 'circuit-breaker');
        }

        try {
            return $next($request);
        } catch (\Throwable $e) {
            if (!$this->isDbConnectionError($e)) {
                throw $e;
            }

            // Trip the breaker so subsequent requests don't try the DB and
            // burn through the per-hour quota even faster.
            SafeCache::put(self::BREAKER_KEY, now()->timestamp + self::BREAKER_COOLDOWN_SECONDS, self::BREAKER_COOLDOWN_SECONDS + 5);

            Log::error('Database connection unavailable, returning friendly 503', [
                'path' => $request->path(),
                'method' => $request->method(),
                'error_code' => (string) $e->getCode(),
                'error' => $e->getMessage(),
            ]);

            return $this->busyResponse(self::BREAKER_COOLDOWN_SECONDS, 'db-unavailable');
        }
    }

    private function busyResponse(int $retryAfter, string $reason): Response
    {
        return response()->json([
            'success' => 0,
            'error' => 'SERVICE_BUSY',
            'message' => 'Our database is under heavy load right now. Please try again in a moment.',
            'reason' => $reason,
            'retry_after' => $retryAfter,
        ], 503)
            ->header('Retry-After', (string) max(1, $retryAfter))
            ->header('X-DB-Breaker', $reason);
    }

    /** Same detection logic as the cache middleware. */
    private function isDbConnectionError(\Throwable $e): bool
    {
        if (!($e instanceof \PDOException) && !($e instanceof QueryException)) {
            return false;
        }
        $code = (string) ($e->getCode() ?: '');
        $msg = strtolower($e->getMessage());

        $hits = ['1226', '1040', '2002', '2006', '2013'];
        foreach ($hits as $needle) {
            if (str_contains($code, $needle) || str_contains($msg, $needle)) return true;
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
        return false;
    }
}
