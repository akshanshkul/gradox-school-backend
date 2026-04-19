<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Cache;

class CachedPersonalAccessToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    public static function findToken($token)
    {
        if (strpos($token, '|') === false) {
            return Cache::remember(
                'sanctum_token_' . hash('sha256', $token),
                now()->addMinutes(5),
                fn () => parent::findToken($token)
            );
        }

        [$id] = explode('|', $token, 2);

        return Cache::remember(
            'sanctum_token_id_' . $id,
            now()->addMinutes(5),
            fn () => static::find($id)
        );
    }

    public function delete()
    {
        Cache::forget('sanctum_token_id_' . $this->id);
        return parent::delete();
    }
}
