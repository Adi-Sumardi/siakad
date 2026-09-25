<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Cache;

/**
 * Secrets that must survive a queue hop without living in jobs.payload
 * (audit T51-a): the OTP code and the activation URL are working
 * credentials, and a serialized job lands in jobs.payload - failed_jobs
 * keeps it forever, readable by anyone with database access (a dump, a
 * replica, a slow-query log). The same discipline notification_logs
 * already had ("the code never reaches the log table") now covers the
 * queue too: the job carries only a key, and the payload is fetched here,
 * encrypted at rest, expiring on its own.
 *
 * The TTL is the consumer's own lifetime: an OTP's 15 minutes (the job's
 * retryUntil horizon - after that the code is useless anyway), an
 * invitation's 7 days (its own token TTL).
 */
class QueueSecret
{
    private const PREFIX = 'qsecret:';

    public static function stash(string $key, string $value, \DateTimeInterface $expiresAt): void
    {
        Cache::put(self::PREFIX.$key, encrypt($value), $expiresAt);
    }

    /** The secret behind a key, or null once its window has closed. */
    public static function take(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        $cipher = Cache::get(self::PREFIX.$key);

        if (! $cipher) {
            return null;
        }

        try {
            return decrypt($cipher);
        } catch (\Throwable) {
            return null;
        }
    }
}
