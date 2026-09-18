<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Services\Notification\NotificationRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The alerting half of the failed-notification sweep (audit C2): the counts
 * an admin needs in order to notice that OTPs, receipts, or reminders are
 * silently not arriving. Aggregates only - no recipients, no payloads; the
 * same "a leak here is a leak about other people's children" stance the
 * activity-log viewer keeps.
 *
 * Central admin only for now, for the same reason as ActivityLogController:
 * notification_logs has no school_unit_id, and scoping its morph to
 * Bill/Payment/Student per unit is easy to get subtly wrong.
 */
class NotificationFailureController extends Controller
{
    public function summary(): JsonResponse
    {
        return response()->json(['failures' => [
            'failed_24h' => $this->failedSince(24)->count(),
            'failed_7d' => $this->failedSince(24 * 7)->count(),

            // Rows the sweep will never pick up again - the human-action set.
            'exhausted_7d' => $this->failedSince(24 * 7)
                ->where('attempts', '>=', NotificationRetryService::MAX_ATTEMPTS)
                ->count(),

            // updated_at on a failed row is its most recent attempt.
            'last_failed_at' => $this->failedSince(24 * 7)
                ->latest('updated_at')
                ->value('updated_at')?->toIso8601String(),

            'top_templates' => $this->failedSince(24 * 7)
                ->select('template', DB::raw('count(*) as total'))
                ->groupBy('template')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn ($row) => ['template' => $row->template, 'count' => (int) $row->total])
                ->all(),
        ]]);
    }

    private function failedSince(int $hours): \Illuminate\Database\Eloquent\Builder
    {
        return NotificationLog::query()
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subHours($hours));
    }
}
