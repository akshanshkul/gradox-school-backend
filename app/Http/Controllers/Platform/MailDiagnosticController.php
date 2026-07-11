<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\MailDiagnosticService;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;

/**
 * Platform-admin controller for mail health diagnostics.
 *
 * Surfaces:
 *   GET    /platform/mail/config      sanitised view of mail config
 *   GET    /platform/mail/probe       live SMTP probe (no actual send)
 *   POST   /platform/mail/test        actually send a test message
 *   GET    /platform/mail/failures    recent send failures (ring buffer)
 *   DELETE /platform/mail/failures    clear the buffer (after fixing)
 *
 * All routes are mounted inside the existing platform-admin auth
 * middleware in routes/platform.php so only SaaS admins reach them.
 *
 * Every successful test-send writes a platform audit log so we can
 * trace "who probed mail and when" when investigating support
 * tickets.
 */
class MailDiagnosticController extends Controller
{
    public function __construct(private PlatformAuditService $audit) {}

    public function config(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => MailDiagnosticService::config(),
        ]);
    }

    public function probe(Request $request)
    {
        $result = MailDiagnosticService::probe();
        // No audit log on probes — they're cheap, idempotent, and we
        // don't want the audit log spammed if someone hits refresh.
        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    public function test(Request $request)
    {
        $data = $request->validate([
            'to'   => 'required|email',
            'note' => 'nullable|string|max:500',
        ]);

        $result = MailDiagnosticService::sendTest($data['to'], $data['note'] ?? null);

        $this->audit->log(
            $request->user()->id,
            'mail.diagnostic.test_send',
            'mail',
            null,
            [
                'to'         => $data['to'],
                'ok'         => $result['ok'],
                'elapsed_ms' => $result['elapsed_ms'] ?? null,
                'error'      => $result['error'] ?? null,
            ],
            $request
        );

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    public function failures(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'failures' => MailDiagnosticService::recentFailures(),
            ],
        ]);
    }

    public function clearFailures(Request $request)
    {
        MailDiagnosticService::clearFailures();
        $this->audit->log($request->user()->id, 'mail.diagnostic.clear_failures', 'mail', null, [], $request);

        return response()->json([
            'success' => true,
            'message' => 'Failure buffer cleared.',
        ]);
    }
}
