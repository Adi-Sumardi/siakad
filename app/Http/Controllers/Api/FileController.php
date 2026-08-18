<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\Announcement;
use App\Models\PointRecord;
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
        return $this->stream(
            Achievement::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail()->sertifikat_path
        );
    }

    public function achievementFoto(Request $request, string $ulid): StreamedResponse
    {
        return $this->stream(
            Achievement::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail()->foto_kegiatan_path
        );
    }

    public function pointEvidence(Request $request, string $ulid): StreamedResponse
    {
        return $this->stream(
            PointRecord::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail()->evidence_path
        );
    }

    /**
     * An announcement's attachment has no per-row owner the way a bill or an
     * achievement does - anyone signed in who can see the notice in their feed
     * can open what came with it.
     */
    public function announcementFile(Request $request, string $ulid): StreamedResponse
    {
        $announcement = Announcement::where('ulid', $ulid)->live()->firstOrFail();

        return $this->stream($announcement->file_path);
    }

    private function stream(?string $path): StreamedResponse
    {
        abort_if(! $path || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
