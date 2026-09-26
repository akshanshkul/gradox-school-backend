<?php

namespace App\Traits;

use App\Services\PublicSiteCache;

/**
 * Drops the school's cached public landing-page payload (Valkey) whenever
 * a landing model (banner / section / card) is saved or deleted.
 */
trait ClearsPublicSiteCache
{
    public static function bootClearsPublicSiteCache()
    {
        $flush = function ($model) {
            $schoolId = $model->school_id
                ?? (method_exists($model, 'section') ? $model->section()->value('school_id') : null);
            PublicSiteCache::forgetSchoolId($schoolId);
        };

        static::saved($flush);
        static::deleted($flush);
    }
}
