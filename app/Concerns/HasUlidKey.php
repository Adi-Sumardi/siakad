<?php

namespace App\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model a ULID and makes it the route key.
 *
 * Every resource the frontend can address is addressed this way: sequential ids
 * in URLs leak how many students the school has and invite enumeration by
 * anyone who can reach the endpoint.
 */
trait HasUlidKey
{
    public static function bootHasUlidKey(): void
    {
        static::creating(function (self $model) {
            if (empty($model->ulid)) {
                $model->ulid = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
