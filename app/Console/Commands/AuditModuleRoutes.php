<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Lists every authenticated API route and whether it carries a
 * `module:X` middleware OR is explicitly tagged `@core`.
 *
 * Used to:
 *   - Audit Phase A → B transition: before Phase B (where the
 *     middleware actually enforces), every route should either be
 *     gated or @core-tagged. The `--strict` flag fails the command
 *     (non-zero exit) when ANY route is missing — wire this into CI
 *     to prevent regressions.
 *   - Spot routes that drift over time (a new endpoint added without
 *     a module tag).
 *
 * Tagging is done by either:
 *   1. `Route::middleware('module:X')->...`  — the gate is on
 *   2. A line comment near the route saying  // @core
 *      (the command scans the route's controller method for that
 *       comment so the auditor can mark routes that intentionally
 *       skip the module gate)
 */
class AuditModuleRoutes extends Command
{
    protected $signature = 'modules:audit-routes {--strict : Exit non-zero if any auth route is ungated}';
    protected $description = 'Audit every authenticated API route for a module: gate or @core tag.';

    public function handle(): int
    {
        $routes = collect(Route::getRoutes())->filter(function ($route) {
            $middlewares = $route->gatherMiddleware();
            // We only care about routes behind auth:sanctum (the place
            // module gating matters). Public routes need no module.
            return in_array('auth:sanctum', $middlewares, true);
        });

        $gated = [];
        $core  = [];
        $bare  = [];

        foreach ($routes as $route) {
            $middlewares = $route->gatherMiddleware();
            $moduleTag = collect($middlewares)
                ->first(fn($m) => str_starts_with((string) $m, 'module:'));

            if ($moduleTag) {
                $gated[] = ['uri' => $route->uri(), 'method' => implode('|', $route->methods()), 'tag' => $moduleTag];
                continue;
            }

            // Look for @core in the action's source. Lightweight check:
            // open the controller file and grep for @core near the
            // method name. Good-enough heuristic for an internal audit.
            $action = $route->getAction();
            $isCore = false;
            if (is_string($action['controller'] ?? null) && str_contains($action['controller'], '@')) {
                [$class, $method] = explode('@', $action['controller']);
                try {
                    $ref = new \ReflectionMethod($class, $method);
                    $doc = (string) ($ref->getDocComment() ?? '');
                    if (str_contains($doc, '@core')) {
                        $isCore = true;
                    }
                } catch (\Throwable $e) { /* swallow */ }
            }

            if ($isCore) {
                $core[] = ['uri' => $route->uri(), 'method' => implode('|', $route->methods())];
            } else {
                $bare[] = ['uri' => $route->uri(), 'method' => implode('|', $route->methods())];
            }
        }

        $this->info("Auth routes: {$routes->count()}");
        $this->line("  gated:    " . count($gated));
        $this->line("  @core:    " . count($core));
        $this->line("  ungated:  " . count($bare));

        if (!empty($bare)) {
            $this->newLine();
            $this->warn('Routes without module: middleware or @core tag:');
            foreach (array_slice($bare, 0, 80) as $r) {
                $this->line("  [{$r['method']}] /{$r['uri']}");
            }
            if (count($bare) > 80) {
                $this->line('  … (' . (count($bare) - 80) . ' more)');
            }
        }

        if ($this->option('strict') && !empty($bare)) {
            $this->error('STRICT mode: ' . count($bare) . ' route(s) lack module gating.');
            return 1;
        }

        return 0;
    }
}
