<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SafeCache
{
    /**
     * Reverted to direct execution as per user request to remove Redis and go "direct to db".
     */
    public static function remember($key, $seconds, $callback)
    {
        // Direct execution of the logic without caching
        return $callback();
    }

    public static function forget($key)
    {
        // No-op as caching is disabled
        return true;
    }

    public static function get($key)
    {
        return null;
    }

    public static function put($key, $value, $seconds)
    {
        return true;
    }
}
