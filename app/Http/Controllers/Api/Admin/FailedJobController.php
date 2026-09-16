<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The failed-jobs leg of the ruang kontrol (/admin/monitoring, audit C7):
 * the queue's dead-letter shelf. Today only ProcessPmbHandoffEvent can land
 * here, but the shelf is generic and reading it costs nothing.
 *
 * Read-only on purpose in v1: retrying a job before its CAUSE is fixed just
 * re-fails it, and the fix lives on the server - recovery is `php artisan
 * queue:retry {uuid}` once the cause is addressed. Payloads never leave the
 * API; the displayName (the job class) plus an exception excerpt is what a
 * human needs to recognise the failure.
 */
class FailedJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $jobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json(['jobs' => [
            'data' => collect($jobs->items())->map(fn ($job) => [
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'display_name' => data_get(json_decode((string) $job->payload, true), 'displayName'),
                'exception' => mb_substr((string) $job->exception, 0, 200),
                // Query-builder rows come back as raw 'Y-m-d H:i:s' strings,
                // which Safari's Date parser rejects - convert before leaving.
                'failed_at' => Carbon::parse($job->failed_at)->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'total' => $jobs->total(),
            ],
        ]]);
    }
}
