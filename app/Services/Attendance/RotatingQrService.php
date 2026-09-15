<?php

namespace App\Services\Attendance;

use Illuminate\Support\Carbon;

/**
 * The rotating QR shared by both self check-in surfaces (DESAIN-PRESENSI-
 * HARIAN.md §5D/§6 layer 1): the gate screen TU holds up, and the roll-call
 * screen a subject teacher projects. The code a student scans is an HMAC over
 * a scope string and the current 30-second window, keyed by app.key - nothing
 * is stored, so there is no token row to leak, replay, or sweep, and a code
 * from one session can never validate at another's. Verification accepts the
 * previous window as well: a code rendered at second 29 of its window stays
 * valid for the ~60 seconds the design promises, and a photographed QR is
 * dead a minute later.
 *
 * The scope prefixes keep a daily session's code from ever verifying against
 * a lesson session even if the two tables ever minted the same ULID.
 */
class RotatingQrService
{
    public const WINDOW_SECONDS = 30;

    /** The scope of a daily gate session's rotating code. */
    public static function dailyScope(string $sessionUlid): string
    {
        return 'daily:'.$sessionUlid;
    }

    /** The scope of a per-lesson roll-call session's rotating code. */
    public static function lessonScope(string $sessionUlid): string
    {
        return 'lesson:'.$sessionUlid;
    }

    /** The code to render on the screen right now, e.g. "9F3A21BC". */
    public function code(string $scope, ?Carbon $now = null): string
    {
        $window = intdiv(($now ?? Carbon::now('Asia/Jakarta'))->getTimestamp(), self::WINDOW_SECONDS);

        return strtoupper(substr(hash_hmac('sha256', $scope.'|'.$window, $this->key()), 0, 8));
    }

    /** True when the scanned code is this window's or the one before it (hash_equals: constant time, code input is user-controlled). */
    public function verify(string $scope, string $scanned, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now('Asia/Jakarta');
        $scanned = strtoupper(trim($scanned));

        foreach ([0, -1] as $offset) {
            $reference = $this->code($scope, $now->copy()->addSeconds($offset * self::WINDOW_SECONDS));

            if (hash_equals($reference, $scanned)) {
                return true;
            }
        }

        return false;
    }

    /** Seconds until the code the screen is showing becomes stale - the screen polls its next code after exactly this long. */
    public function secondsUntilRotation(?Carbon $now = null): int
    {
        $now ??= Carbon::now('Asia/Jakarta');

        return self::WINDOW_SECONDS - ($now->getTimestamp() % self::WINDOW_SECONDS);
    }

    private function key(): string
    {
        return (string) config('app.key');
    }
};
