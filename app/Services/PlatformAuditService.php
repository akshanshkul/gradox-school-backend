<?php

namespace App\Services;

use App\Models\PlatformAuditLog;
use Illuminate\Http\Request;

class PlatformAuditService
{
    public function log(
        ?int $platformAdminId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $details = [],
        ?Request $request = null
    ): PlatformAuditLog {
        return PlatformAuditLog::create([
            'platform_admin_id' => $platformAdminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $details,
            'ip_address' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255),
        ]);
    }
}
