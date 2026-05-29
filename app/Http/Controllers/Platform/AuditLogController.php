<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = PlatformAuditLog::query()->with('admin:id,name,email');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($adminId = $request->query('platform_admin_id')) {
            $query->where('platform_admin_id', $adminId);
        }

        if ($targetType = $request->query('target_type')) {
            $query->where('target_type', $targetType);
        }

        if ($targetId = $request->query('target_id')) {
            $query->where('target_id', $targetId);
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }
}
