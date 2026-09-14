<?php

namespace App\Services\Attendance;

use App\Models\DailySession;
use Illuminate\Support\Carbon;

/**
 * The rotating gate QR (DESAIN-PRESENSI-HARIAN.md §5D/§6 layer 1). The code a
 * student scans is an HMAC over the session's ULID and the current 30-second
 * window, keyed by app.key - nothing is stored, so there is no token row to
 * leak, replay, or sweep, and a code from one unit's gate can never validate
 * at another unit's morning session. Verification accepts the previous
 * window as well: a code rendered at second 29 of its window stays valid for
 * the ~60 seconds the design promises, and a photographed QR is dead a
 * minute later.
 */
class GateQrService
{
    public const WINDOW_SECONDS = 30;

    /** The code to render on the TU's screen right now, e.g. "9F3A21BC". */
    public function code(DailySession $session, ?Carbon $now = null): string
    {
        $window = intdiv(($now ?? Carbon::now('Asia/Jakarta'))->getTimestamp(), self::WINDOW_SECONDS);

        return strtoupper(substr(hash_hmac('sha256', $session->ulid.'|'.$window, $this->key()), 0, 8));
    }

    /** True when the scanned code is this window's or the one before it (hash_equals: constant time, code input is user-controlled). */
    public function verify(DailySession $session, string $scanned, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now('Asia/Jakarta');
        $scanned = strtoupper(trim($scanned));

        foreach ([0, -1] as $offset) {
            $reference = $this->code($session, $now->copy()->addSeconds($offset * self::WINDOW_SECONDS));

            if (hash_equals($reference, $scanned)) {
                return true;
            }
        }

        return false;
    }

    /** Seconds until the code the TU screen is showing becomes stale - the screen polls its next code after exactly this long. */
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
