<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Models\CachedPersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(CachedPersonalAccessToken::class);

        // Log queries that take longer than 700ms
        \Illuminate\Support\Facades\DB::listen(function ($query) {
            if ($query->time > 700) {
                \Illuminate\Support\Facades\Log::warning('Slow query detected (>' . $query->time . 'ms): ' . $query->sql, [
                    'bindings' => $query->bindings,
                    'time' => $query->time,
                ]);
            }
        });
    }
}
