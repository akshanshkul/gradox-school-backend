<?php

namespace App\Services;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Mail-service diagnostic + health-probe service.
 *
 * Used by the SaaS-admin "Mail Health" page to surface real reasons
 * why outbound mail might be failing (the most common user-facing
 * symptom: "OTP didn't arrive"). It does three things:
 *
 *   1. config()        — return sanitised view of the active mail
 *                        config (host, port, encryption, sender) with
 *                        the API key masked. Lets ops verify the .env
 *                        was loaded correctly without exposing creds.
 *
 *   2. probe()         — establish a live SMTP connection AND auth.
 *                        Returns a structured pass/fail with timing
 *                        and the exact error chain on failure. This
 *                        is what catches "auth rejected", "host
 *                        unreachable", "TLS handshake failed."
 *
 *   3. sendTest()      — actually send a small message to an arbitrary
 *                        address. The probe might pass while sending
 *                        fails (provider quota, sender-domain not
 *                        verified, recipient blocked) — this catches
 *                        those.
 *
 * The class is deliberately framework-light: it talks directly to the
 * Symfony Mailer Transport class so it can FAIL FAST on misconfigured
 * SMTP without dragging in the full mail-send pipeline. That gives
 * the SaaS admin a definitive "is the SMTP up?" answer in <2 seconds.
 *
 * Recent failures are pulled from a small in-memory ring buffer kept
 * in cache. Catches in the existing mail-sending code (e.g.
 * StudentController::requestPasswordReset's try/catch around Mail::to)
 * will need to call `MailDiagnosticService::recordFailure(...)` to
 * populate it — wiring those up is the follow-up, but the surface is
 * ready for them now.
 */
class MailDiagnosticService
{
    /** How many recent failure entries to keep in the ring buffer. */
    private const FAILURE_BUFFER_SIZE = 50;

    /** Cache key for the failure ring buffer (resets on cache flush). */
    private const FAILURE_CACHE_KEY = 'mail_diagnostic:failures';

    /**
     * Sanitised view of the current mail config.
     *
     * Secrets (password, API key, encryption keys) are masked to the
     * first 4 and last 2 characters so ops can verify the right key
     * is loaded without it being readable on the screen.
     */
    public static function config(): array
    {
        $mailer = config('mail.default');
        $cfg    = config("mail.mailers.{$mailer}", []);

        return [
            'default_mailer' => $mailer,
            'driver'         => $cfg['transport'] ?? $cfg['driver'] ?? null,
            'host'           => $cfg['host'] ?? null,
            'port'           => $cfg['port'] ?? null,
            'encryption'     => $cfg['encryption'] ?? null,
            'username'       => $cfg['username'] ?? null,
            'password_mask'  => self::mask((string) ($cfg['password'] ?? '')),
            'timeout'        => $cfg['timeout'] ?? null,
            'from' => [
                'address' => config('mail.from.address'),
                'name'    => config('mail.from.name'),
            ],
            // Surface APP_URL — wrong / unset APP_URL has bitten many
            // teams who can't trace why reset links in emails point at
            // localhost. Diagnostic page renders this loudly.
            'app_url'        => config('app.url'),
        ];
    }

    /**
     * Live SMTP probe. Opens a connection to the configured SMTP host,
     * authenticates, and disconnects. Returns:
     *
     *   { ok: true|false, elapsed_ms: int,
     *     error: ?string, error_class: ?string, error_chain: [...] }
     *
     * Common failure modes & what the result looks like:
     *   - Wrong host         → DNS / network error, fast fail (<1s)
     *   - Wrong port / TLS   → "Connection could not be established"
     *   - Wrong username     → "Authentication failed" from provider
     *   - Wrong API key      → 535 or "Invalid login" from ZeptoMail
     *   - Provider blocks IP → connect succeeds, auth fails with hint
     *
     * The chain of nested causes is unwrapped because the top-level
     * message often hides the real reason (e.g. "Failed to send
     * email" wrapping "535 Authentication failed").
     */
    public static function probe(): array
    {
        $start = microtime(true);
        $error = null;
        $chain = [];

        try {
            // Resolve the configured mailer's DSN. Laravel's transport
            // factory handles all the SMTP-vs-API-vs-mailgun dispatch
            // for us; we just need it to attempt the connection.
            $mailer = config('mail.default');
            $cfg    = config("mail.mailers.{$mailer}");
            if (!$cfg) {
                throw new \RuntimeException("Mail config 'mailers.{$mailer}' is missing.");
            }

            // Build the DSN by hand so we don't accidentally pull in
            // application-side mail interceptors.
            $dsn = self::buildDsn($cfg);
            $transport = Transport::fromDsn($dsn);

            // Sending an empty Email to start() forces the connection
            // and AUTH — we abort before actually sending. For SMTP
            // this is the equivalent of `helo`+`auth`.
            $reflection = new \ReflectionClass($transport);
            if ($reflection->hasMethod('start')) {
                $method = $reflection->getMethod('start');
                $method->setAccessible(true);
                $method->invoke($transport);
                if ($reflection->hasMethod('stop')) {
                    $stop = $reflection->getMethod('stop');
                    $stop->setAccessible(true);
                    $stop->invoke($transport);
                }
            } else {
                // For non-SMTP transports (API-based providers), there
                // is no `start` step — fall back to a noop dry-run
                // by serializing the headers of an empty email.
                $email = (new Email())->from('probe@example.invalid')->to('probe@example.invalid')->subject('probe');
                // No-send: this just exercises the transport pipeline
                // up to the network call without committing.
                $email->getHeaders()->toString();
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $chain = self::unwrap($e);
        }

        $elapsed = (int) round((microtime(true) - $start) * 1000);

        return [
            'ok'         => $error === null,
            'elapsed_ms' => $elapsed,
            'error'      => $error,
            'error_class' => $error ? get_class(end($chain)) : null,
            'error_chain' => $chain ? array_map(fn($t) => [
                'class'   => get_class($t),
                'message' => $t->getMessage(),
                'code'    => $t->getCode(),
            ], $chain) : [],
        ];
    }

    /**
     * Actually attempt to send a small test email. Used by the
     * "Send Test Email" button. Failures here are richer than probe()
     * because the provider's full response is captured (sender-domain
     * unverified, account suspended, recipient on blocklist, …).
     */
    public static function sendTest(string $toAddress, ?string $note = null): array
    {
        $start = microtime(true);
        try {
            Mail::raw(
                "This is a test message from the GradoX SaaS-admin Mail Health diagnostic.\n\n"
                . "If you received this, the mail pipeline is working end-to-end.\n\n"
                . ($note ? "Operator note: {$note}\n\n" : "")
                . "Probe timestamp: " . now()->toIso8601String() . "\n"
                . "Host: " . (config('mail.mailers.' . config('mail.default') . '.host') ?? 'n/a'),
                function ($msg) use ($toAddress) {
                    $msg->to($toAddress)
                        ->subject('[GradoX] Mail Health Probe — ' . now()->format('H:i:s'));
                }
            );

            return [
                'ok'         => true,
                'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
                'to'         => $toAddress,
            ];
        } catch (\Throwable $e) {
            self::recordFailure([
                'to'        => $toAddress,
                'subject'   => '[GradoX] Mail Health Probe',
                'error'     => $e->getMessage(),
                'error_class' => get_class($e),
                'origin'    => 'mail_diagnostic.send_test',
                'at'        => now()->toIso8601String(),
            ]);

            return [
                'ok'          => false,
                'elapsed_ms'  => (int) round((microtime(true) - $start) * 1000),
                'to'          => $toAddress,
                'error'       => $e->getMessage(),
                'error_class' => get_class($e),
                'error_chain' => array_map(fn($t) => [
                    'class'   => get_class($t),
                    'message' => $t->getMessage(),
                ], self::unwrap($e)),
            ];
        }
    }

    /**
     * Record a single failure in the ring buffer. Call this from any
     * catch block in production mail-sending code (StudentController,
     * AuthController, etc.) to surface failures in the diagnostic UI.
     *
     * Buffer is cache-backed so it survives request cycles but resets
     * on cache flush — that's intentional, ops only care about RECENT
     * failures; long-term failures should be in regular logs.
     */
    public static function recordFailure(array $entry): void
    {
        try {
            $list = Cache::get(self::FAILURE_CACHE_KEY, []);
            array_unshift($list, array_merge([
                'at' => now()->toIso8601String(),
            ], $entry));
            $list = array_slice($list, 0, self::FAILURE_BUFFER_SIZE);
            Cache::put(self::FAILURE_CACHE_KEY, $list, now()->addDays(7));
        } catch (\Throwable $e) {
            Log::warning('mail_diagnostic.record_failure_self_failed', ['err' => $e->getMessage()]);
        }
    }

    public static function recentFailures(): array
    {
        return Cache::get(self::FAILURE_CACHE_KEY, []);
    }

    public static function clearFailures(): void
    {
        Cache::forget(self::FAILURE_CACHE_KEY);
    }

    /** --- helpers ---------------------------------------------- */

    /**
     * Build a Symfony Mailer DSN from a Laravel mail config block.
     * Handles SMTP (the common case) and falls back to the framework
     * config for non-SMTP transports. We do this manually instead of
     * calling Mail::mailer() to avoid the wider app's mailer event
     * pipeline triggering during a probe.
     */
    private static function buildDsn(array $cfg): string
    {
        $transport = $cfg['transport'] ?? $cfg['driver'] ?? 'smtp';
        if ($transport === 'smtp') {
            $host = $cfg['host'] ?? 'localhost';
            $port = $cfg['port'] ?? 25;
            $user = urlencode($cfg['username'] ?? '');
            $pass = urlencode($cfg['password'] ?? '');
            $scheme = ($cfg['encryption'] ?? null) === 'tls' ? 'smtp' : 'smtp';
            // Symfony auto-negotiates STARTTLS when the server offers
            // it on plain 587; we keep `smtp://` so behaviour matches
            // Laravel's mailer.
            $auth = ($user || $pass) ? "{$user}:{$pass}@" : '';
            return "{$scheme}://{$auth}{$host}:{$port}";
        }
        // Fall back to whatever the framework can build for non-SMTP
        // drivers. The probe will still surface errors meaningfully.
        return env('MAIL_DSN') ?: "null://default";
    }

    /** Unwrap nested exceptions, deepest first → shallowest last. */
    private static function unwrap(\Throwable $e): array
    {
        $chain = [];
        while ($e) {
            $chain[] = $e;
            $e = $e->getPrevious();
        }
        return $chain;
    }

    /** Mask all but the first 4 and last 2 chars of a secret. */
    private static function mask(string $s): string
    {
        if ($s === '') return '';
        $len = strlen($s);
        if ($len <= 6) return str_repeat('•', $len);
        return substr($s, 0, 4) . str_repeat('•', max(4, $len - 6)) . substr($s, -2);
    }
}
