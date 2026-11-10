<?php

namespace App\Console\Commands;

use App\Models\DailyRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Gate check-in snapshots (docs/09-ABSEN-WAJAH-GERBANG.md) are evidence for
 * disputes about the last few weeks, not an archive of children's faces.
 * Each night the ones past the retention window are deleted and their rows
 * forget the path. Reference faces (student_faces) are not touched.
 */
class PruneFacePhotos extends Command
{
    protected $signature = 'attendance:prune-face-photos';

    protected $description = 'Hapus foto bukti absen wajah yang lewat masa simpan';

    public function handle(): int
    {
        $cutoff = Carbon::now('Asia/Jakarta')->subDays((int) config('services.face.photo_retention_days', 30))->toDateString();
        $deleted = 0;

        DailyRecord::query()
            ->whereNotNull('photo_path')
            ->whereDate('date', '<', $cutoff)
            ->chunkById(500, function ($records) use (&$deleted) {
                foreach ($records as $record) {
                    Storage::disk('local')->delete($record->photo_path);
                    $record->forceFill(['photo_path' => null])->saveQuietly();
                    $deleted++;
                }
            });

        $this->info("{$deleted} foto bukti absen dihapus.");

        return self::SUCCESS;
    }
}
