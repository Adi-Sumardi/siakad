<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The server half of gate check-in by face (docs/09-ABSEN-WAJAH-GERBANG.md).
 *
 * The browser runs the face model and the blink check, then posts a 128-float
 * descriptor plus one JPEG snapshot. This class decides: it validates both,
 * compares the descriptor against the student's stored reference, and keeps
 * the snapshot as evidence. The reference descriptor never goes back out.
 */
class FaceMatcher
{
    public const DESCRIPTOR_LENGTH = 128;

    /** A 640px JPEG at quality 0.8 is ~60 KB; anything near this cap is not a webcam frame. */
    private const MAX_PHOTO_BYTES = 400_000;

    /** @return list<float> */
    public function descriptor(mixed $input): array
    {
        if (! is_array($input) || count($input) !== self::DESCRIPTOR_LENGTH) {
            throw new RuntimeException('Data wajah tidak lengkap - ulangi verifikasi wajah.');
        }

        $values = [];

        foreach (array_values($input) as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || abs((float) $value) > 10) {
                throw new RuntimeException('Data wajah tidak valid - ulangi verifikasi wajah.');
            }

            $values[] = (float) $value;
        }

        return $values;
    }

    /** @param list<float> $a @param list<float> $b */
    public function distance(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $i => $value) {
            $sum += ($value - (float) ($b[$i] ?? 0)) ** 2;
        }

        return sqrt($sum);
    }

    public function matches(float $distance): bool
    {
        return $distance <= (float) config('services.face.max_distance', 0.5);
    }

    /** Stores a base64 JPEG (with or without the data: prefix) on the private disk and returns its path. */
    public function storePhoto(string $base64, string $directory): string
    {
        $data = base64_decode((string) preg_replace('#^data:image/jpeg;base64,#', '', $base64), true);

        // JPEG magic bytes - the only format the camera page produces.
        if ($data === false || strlen($data) > self::MAX_PHOTO_BYTES || ! str_starts_with($data, "\xFF\xD8\xFF")) {
            throw new RuntimeException('Foto wajah tidak valid - ulangi verifikasi wajah.');
        }

        $path = trim($directory, '/').'/'.Str::ulid().'.jpg';
        Storage::disk('local')->put($path, $data);

        return $path;
    }

    public function copyPhoto(string $from, string $directory): string
    {
        $path = trim($directory, '/').'/'.Str::ulid().'.jpg';
        Storage::disk('local')->copy($from, $path);

        return $path;
    }

    public function deletePhoto(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
