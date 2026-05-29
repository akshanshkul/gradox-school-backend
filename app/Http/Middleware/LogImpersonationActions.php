<?php

namespace App\Http\Middleware;

use App\Services\PlatformAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirrors every state-changing request made with an impersonation token
 * into platform_audit_logs, so any action a platform admin performs while
 * "Entered as admin" is recorded under their identity on the platform side.
 *
 * The middleware is a no-op for normal user tokens (one ability-array check).
 */
class LogImpersonationActions
{
    private const ABILITY_PREFIX = 'platform-impersonation:';
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const REDACT_KEYS = ['password', 'password_confirmation', 'current_password', 'token', 'access_token', 'secret', 'api_key'];

    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (!in_array($request->method(), self::WRITE_METHODS, true)) {
                return $response;
            }

            $user = $request->user();
            if (!$user || !method_exists($user, 'currentAccessToken')) {
                return $response;
            }

            $token = $user->currentAccessToken();
            if (!$token) {
                return $response;
            }

            $platformAdminId = $this->extractPlatformAdminId($token->abilities ?? []);
            if ($platformAdminId === null) {
                return $response;
            }

            $this->audit->log(
                $platformAdminId,
                'impersonation.action',
                'school',
                (int) ($user->school_id ?? 0),
                [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'route' => optional($request->route())->getName()
                        ?? optional($request->route())->getActionName(),
                    'acting_as_user_id' => $user->id,
                    'acting_as_user_email' => $user->email,
                    'response_status' => $response->getStatusCode(),
                    'payload' => $this->redact($request->all()),
                ],
                $request
            );
        } catch (\Throwable $e) {
            // Logging must never break the actual request. Swallow.
            report($e);
        }

        return $response;
    }

    private function extractPlatformAdminId(array $abilities): ?int
    {
        foreach ($abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, self::ABILITY_PREFIX)) {
                $id = (int) substr($ability, strlen(self::ABILITY_PREFIX));
                return $id > 0 ? $id : null;
            }
        }
        return null;
    }

    private function redact(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT_KEYS, true)) {
                $clean[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $clean[$key] = $this->redact($value);
            } elseif (is_string($value) && strlen($value) > 500) {
                $clean[$key] = substr($value, 0, 500) . '… [truncated]';
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }
}
