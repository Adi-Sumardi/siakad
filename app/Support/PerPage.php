<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Clamps a client-supplied per_page to a sane band (audit 2026-10-05).
 *
 * Every paginated endpoint passed $request->integer('per_page', N) straight
 * into paginate(), so any authenticated user could ask for
 * per_page=10000000 and dump a whole table (students hydrate guardians and
 * enrollments per row) in one request. One clamp, one band, everywhere.
 */
final class PerPage
{
    public static function clamp(Request $request, int $default = 25, int $max = 500): int
    {
        return min($max, max(1, $request->integer('per_page', $default)));
    }
}
