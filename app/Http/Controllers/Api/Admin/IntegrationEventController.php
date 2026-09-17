<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPmbHandoffEvent;
use App\Models\ActivityLog;
use App\Models\IntegrationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The webhook-inbox leg of the ruang kontrol (/admin/monitoring, audit C7):
 * integration_events rows previously visible only by opening the database.
 *
 * Central admin only, ActivityLogController's stance again - no
 * school_unit_id to scope by. Payloads never leave the API (large, and
 * PII-heavy); event_type plus the stored error already answer "what broke".
 */
class IntegrationEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $source = $request->string('source')->value();
        $status = $request->string('status')->value();

        $events = IntegrationEvent::query()
            ->when(in_array($source, ['pmb', 'xendit', 'sendagopay', 'billing_api'], true), fn ($q) => $q->where('source', $source))
            ->when(in_array($status, ['received', 'processed', 'failed'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        $rows = $events->getCollection()->load('student:id,ulid');

        return response()->json(['events' => [
            'data' => $rows->map(fn (IntegrationEvent $event) => $this->row($event)),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'total' => $events->total(),
                'per_page' => $events->perPage(),
            ],
        ]]);
    }

    /**
     * Puts a PMB event back on the processing queue. Safe by construction:
     * PmbHandoffProcessor re-guards isProcessed() and every write is an
     * idempotent upsert, so re-running a failed (or stuck received) event
     * cannot duplicate a student. The classic flow is "unknown unit" - the
     * admin adds the unit, presses this, and the handoff completes.
     *
     * PMB only: the xendit/sendagopay handlers run inline in their
     * controllers and have no replayable service to dispatch.
     */
    public function reprocess(Request $request, string $ulid): JsonResponse
    {
        $event = IntegrationEvent::where('ulid', $ulid)->firstOrFail();

        if ($event->source !== 'pmb' || $event->status === 'processed') {
            throw new HttpResponseException(response()->json([
                'message' => 'Hanya event PMB yang gagal atau tertunda yang bisa diproses ulang.',
            ], 422));
        }

        // Only relevant where the queue runs sync (tests, local): the job
        // throws, the row is already marked failed by the processor, and the
        // response below still tells the truth. On the database queue the
        // dispatch itself cannot fail here.
        try {
            ProcessPmbHandoffEvent::dispatch($event->id);
        } catch (\Throwable) {
            // The row is the source of truth.
        }

        ActivityLog::record($request->user(), 'integration_event.reprocessed', $event, [
            'source' => $event->source,
            'event_type' => $event->event_type,
        ]);

        return response()->json([
            'status' => 'queued',
            'message' => 'Event dimasukkan kembali ke antrian proses.',
        ], 202);
    }

    /** @return array<string, mixed> */
    private function row(IntegrationEvent $event): array
    {
        return [
            'ulid' => $event->ulid,
            'source' => $event->source,
            'event_type' => $event->event_type,
            'event_id' => $event->event_id,
            'status' => $event->status,
            'attempts' => $event->attempts,
            'student_ulid' => $event->student?->ulid,
            'error' => mb_substr((string) $event->error, 0, 200),
            'processed_at' => $event->processed_at?->toIso8601String(),
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }
}
