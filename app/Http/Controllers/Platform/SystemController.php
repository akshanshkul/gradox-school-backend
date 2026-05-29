<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemController extends Controller
{
    public function health()
    {
        return response()->json([
            'app' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'environment' => config('app.env'),
                'debug' => (bool) config('app.debug'),
                'timezone' => config('app.timezone'),
                'server_time' => now()->toIso8601String(),
            ],
            'database' => $this->database(),
            'queue' => $this->queue(),
            'storage' => $this->storage(),
            'cache' => $this->cache(),
        ]);
    }

    private function database(): array
    {
        try {
            $start = microtime(true);
            DB::select('select 1');
            $ms = round((microtime(true) - $start) * 1000, 1);

            $connections = $this->databaseConnections();

            return array_merge([
                'connection' => config('database.default'),
                'driver' => config('database.connections.' . config('database.default') . '.driver'),
                'connected' => true,
                'ping_ms' => $ms,
            ], $connections);
        } catch (\Throwable $e) {
            return [
                'connection' => config('database.default'),
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Best-effort live-connection metrics. Supported on MySQL/MariaDB; other
     * drivers gracefully return nulls so the dashboard still renders.
     */
    private function databaseConnections(): array
    {
        $out = [
            'threads_connected' => null,
            'threads_running' => null,
            'max_connections' => null,
            'max_used_connections' => null,
            'connection_usage_percent' => null,
        ];

        $driver = config('database.connections.' . config('database.default') . '.driver');
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return $out;
        }

        try {
            $statusRows = DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Max_used_connections')");
            $varRows = DB::select("SHOW VARIABLES WHERE Variable_name = 'max_connections'");

            $status = [];
            foreach ($statusRows as $row) {
                $status[$row->Variable_name] = (int) $row->Value;
            }
            $vars = [];
            foreach ($varRows as $row) {
                $vars[$row->Variable_name] = (int) $row->Value;
            }

            $out['threads_connected'] = $status['Threads_connected'] ?? null;
            $out['threads_running'] = $status['Threads_running'] ?? null;
            $out['max_used_connections'] = $status['Max_used_connections'] ?? null;
            $out['max_connections'] = $vars['max_connections'] ?? null;

            if ($out['threads_connected'] !== null && $out['max_connections']) {
                $out['connection_usage_percent'] = round(($out['threads_connected'] / $out['max_connections']) * 100, 1);
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    private function queue(): array
    {
        $out = [
            'driver' => config('queue.default'),
            'failed_jobs' => null,
            'pending_jobs' => null,
        ];
        try {
            if (Schema::hasTable('failed_jobs')) {
                $out['failed_jobs'] = (int) DB::table('failed_jobs')->count();
            }
            if (Schema::hasTable('jobs')) {
                $out['pending_jobs'] = (int) DB::table('jobs')->count();
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    private function storage(): array
    {
        try {
            $base = storage_path();
            $free = disk_free_space($base);
            $total = disk_total_space($base);
            return [
                'path' => $base,
                'free_bytes' => $free === false ? null : (int) $free,
                'total_bytes' => $total === false ? null : (int) $total,
                'used_percent' => ($free && $total) ? round((($total - $free) / $total) * 100, 1) : null,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function cache(): array
    {
        return [
            'default_store' => config('cache.default'),
        ];
    }
}
