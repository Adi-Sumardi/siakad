<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\Announcement;
use App\Models\PointRecord;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the private files this app stores - achievement certificates,
 * activity photos, point evidence - through one gate: whoever is asking must
 * be able to see the row that owns the file, via the same visibleTo() scope
 * that already governs the JSON for it.
 *
 * One controller for all three rather than one per role/resource, because the
 * check is identical in every case - only the model and the column differ.
 */
class FileController extends Controller
{
    public function achievementSertifikat(Request $request, string $ulid): StreamedResponse
    {
        $achievement = Achievement::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        return $this->stream($achievement->sertifikat_path, $achievement->sertifikat_name);
    }

    public function achievementFoto(Request $request, string $ulid): StreamedResponse
    {
        $achievement = Achievement::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        return $this->stream($achievement->foto_kegiatan_path, $achievement->foto_kegiatan_name);
    }

    public function pointEvidence(Request $request, string $ulid): StreamedResponse
    {
        $record = PointRecord::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        return $this->stream($record->evidence_path, $record->evidence_name);
    }

    /**
     * Same rule as everything else this controller serves: visible in the
     * feed, or not opened at all. Announcement::scopeVisibleTo() only covers
     * staff (a guardian falls through it to whereRaw('1 = 0')) - a guardian's
     * visibility depends on which of their children the notice targets, the
     * same aggregation WaliAnnouncementController::index() does per child.
     */
    public function announcementFile(Request $request, string $ulid): StreamedResponse
    {
        $announcement = Announcement::where('ulid', $ulid)->live()->firstOrFail();
        $user = $request->user();

        $visible = $user?->isGuardian()
            ? Student::visibleTo($user)->get()
                ->contains(fn ($student) => Announcement::whereKey($announcement->id)->forStudent($student)->exists())
            : Announcement::whereKey($announcement->id)->visibleTo($user)->exists();

        abort_unless($visible, 404);

        return $this->stream($announcement->file_path, $announcement->file_name);
    }

    /**
     * $name is what the uploader called it. Older rows recorded before this
     * was captured have none, so the storage path's own (random) basename is
     * still the fallback, not an error.
     */
    private function stream(?string $path, ?string $name = null): StreamedResponse
    {
        abort_if(! $path || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $name);
    }
}
