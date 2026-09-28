<?php

namespace App\Services\Kesiswaan;

use RuntimeException;

/**
 * A STATE failure in the achievement decision flow (422 upstream), as
 * opposed to the permission refusals in refusalFor() which surface as 403:
 * the decider is allowed, the world just is not ready - no active term to
 * file points under, or points sent for a teacher achievement that cannot
 * carry any.
 *
 * Typed (not a str_contains on the message) so the API layer can map it
 * without sniffing prose.
 */
class AchievementStateFailure extends RuntimeException
{
    public static function termMissing(): self
    {
        return new self('Tidak ada semester aktif - poin tidak bisa dicatat. Verifikasi tanpa poin, atau aktifkan term terlebih dahulu.');
    }

    public static function pointsNotApplicable(): self
    {
        return new self('Prestasi guru tidak memberi poin - poin hanya dicatat untuk prestasi siswa.');
    }
}
