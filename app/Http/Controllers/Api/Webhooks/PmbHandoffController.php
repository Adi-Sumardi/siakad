<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPmbHandoffEvent;
use App\Models\IntegrationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives student handoffs from PMB.
 *
 * The endpoint does as little as possible: verify (in middleware), validate,
 * record, queue, answer. Everything that can be slow happens in the job, so
 * PMB's HTTP client never times out and redelivers a handoff that is already
 * halfway written.
 */
class PmbHandoffController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event' => 'required|string|in:student.enrolled,student.updated,student.cancelled',
            'event_id' => 'required|string|max:64',
            'occurred_at' => 'nullable|date',

            'student' => 'required|array',
            'student.pmb_ulid' => 'required|string|max:64',
            'student.nama_lengkap' => 'required|string|max:200',
            'student.jenis_kelamin' => 'required|in:L,P',
            'student.unit_code' => 'required|string|max:64',
            'student.academic_year' => 'nullable|string|max:16',
            'student.tanggal_lahir' => 'nullable|date',

            'guardians' => 'array',
            'guardians.*.nama' => 'required_with:guardians|string|max:200',
            'guardians.*.hubungan' => 'required_with:guardians|in:ayah,ibu,wali',
            'guardians.*.email' => 'nullable|email|max:200',
            'guardians.*.no_hp' => 'nullable|string|max:32',
            'guardians.*.is_primary' => 'boolean',
        ]);

        // firstOrCreate on the unique event_id is the whole idempotency story:
        // a redelivered event finds the existing row and is answered 202
        // without being queued a second time.
        $event = IntegrationEvent::firstOrCreate(
            ['event_id' => $validated['event_id']],
            [
                'source' => 'pmb',
                'event_type' => $validated['event'],
                // The whole body, not just $validated. validate() returns only
                // the keys it was given rules for, so storing that would have
                // silently dropped NIK, NISN, and the address - fields the rules
                // do not constrain but the processor does write. The body is
                // already proven to come from PMB by the HMAC, and the processor
                // reads named keys only.
                'payload' => $request->all(),
                'status' => 'received',
            ]
        );

        if (! $event->wasRecentlyCreated) {
            Log::info('[PMB handoff] Duplicate event ignored', [
                'event_id' => $event->event_id,
                'status' => $event->status,
            ]);

            return response()->json([
                'status' => $event->status,
                'event_id' => $event->event_id,
                'duplicate' => true,
            ], 202);
        }

        ProcessPmbHandoffEvent::dispatch($event->id);

        return response()->json([
            'status' => 'queued',
            'event_id' => $event->event_id,
        ], 202);
    }
}
