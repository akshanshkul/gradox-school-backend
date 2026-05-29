<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformBroadcast;
use App\Models\School;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BroadcastController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index()
    {
        return response()->json([
            'broadcasts' => PlatformBroadcast::with('admin:id,name,email')
                ->orderByDesc('id')
                ->paginate(25),
        ]);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            'type' => 'nullable|in:info,success,warning,danger',
            'audience' => 'nullable|in:all,active,trialing,suspended,expired',
            'channel' => 'nullable|in:in_app,email,both',
        ]);

        $audience = $data['audience'] ?? 'all';
        $channel = $data['channel'] ?? 'in_app';
        $type = $data['type'] ?? 'info';

        $schoolQuery = School::query();
        if ($audience !== 'all') {
            $schoolQuery->where('subscription_status', $audience);
        }
        $schoolIds = $schoolQuery->pluck('id');

        $broadcast = PlatformBroadcast::create([
            'sent_by_admin_id' => $request->user()->id,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'type' => $type,
            'audience' => $audience,
            'channel' => $channel,
            'sent_at' => now(),
        ]);

        $sentTo = 0;
        if (in_array($channel, ['in_app', 'both'], true)) {
            $sentTo += $this->deliverInApp($schoolIds, $data['subject'], $data['body'], $type, $broadcast->id);
        }
        // Email channel is intentionally deferred — requires per-school SMTP/queue
        // configuration. The broadcast row is still saved so it can be re-sent
        // once email delivery is wired up.

        $broadcast->update(['sent_to_count' => $sentTo]);

        $this->audit->log(
            $request->user()->id,
            'broadcast.send',
            'broadcast',
            $broadcast->id,
            ['audience' => $audience, 'channel' => $channel, 'recipients' => $sentTo],
            $request
        );

        return response()->json(['broadcast' => $broadcast->fresh()]);
    }

    private function deliverInApp($schoolIds, string $subject, string $body, string $type, int $broadcastId): int
    {
        $admins = User::whereIn('school_id', $schoolIds)
            ->where('status', 'active')
            ->whereHas('role_relation', function ($q) {
                $q->whereIn('slug', ['administrator', 'super-admin', 'admin']);
            })
            ->get(['id', 'school_id']);

        if ($admins->isEmpty()) {
            return 0;
        }

        $rows = $admins->map(function ($u) use ($subject, $body, $type, $broadcastId) {
            return [
                'school_id' => $u->school_id,
                'notifiable_type' => User::class,
                'notifiable_id' => $u->id,
                'title' => $subject,
                'message' => $body,
                'type' => $type,
                'data' => json_encode(['source' => 'platform_broadcast', 'broadcast_id' => $broadcastId]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all();

        DB::table('notifications')->insert($rows);
        return count($rows);
    }
}
