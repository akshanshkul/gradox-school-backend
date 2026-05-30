<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // Reasonable default for a multi-AJAX SaaS dashboard. The old value
        // (60/min) trivially tripped "TOO MANY ATTEMPTS" any time a page made
        // a handful of parallel calls. Configurable via .env without redeploy.
        RateLimiter::for('api', function (Request $request) {
            $perMinute = (int) env('API_RATE_LIMIT_PER_MINUTE', 300);
            return Limit::perMinute(max(60, $perMinute))->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('api')
                ->prefix('api/platform')
                ->group(base_path('routes/platform.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
