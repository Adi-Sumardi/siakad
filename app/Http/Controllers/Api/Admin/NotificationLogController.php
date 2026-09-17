<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\NotificationLog;
use App\Services\Notification\NotificationRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The notifikasi leg of the ruang kontrol (/admin/monitoring, audit C7):
 * the failed-notification rows the dashboard card only counts, plus the
 * manual "kirim ulang" action for rows the bounded sweep gave up on (or was
 * never allowed to touch).
 *
 * Central admin only for now, for the same reason as ActivityLogController:
 * notification_logs has no school_unit_id to scope an admin_unit by, and a
 * leak here is a leak about other people's children. Recipients ARE shown -
 * following up with that family is the point of the page - but payloads
 * never are: the invitation payload was stripped on purpose, and nothing on
 * this screen needs the message body to make a decision.
 */
class NotificationLogController extends Controller
{
    public function __construct(private NotificationRetryService $retry) {}

    public function index(Request $request): JsonResponse
    {
        // The dashboard drill-down lands here with no params at all, so the
        // default view is the failed list; "all" is the explicit way out.
        $status = $request->string('status')->value() ?: 'failed';
        $channel = $request->string('channel')->value();

        $logs = NotificationLog::query()
            ->when(in_array($status, ['failed', 'sent', 'queued'], true), fn ($q) => $q->where('status', $status))
            ->when($request->string('template')->value(), fn ($q, $template) => $q->where('template', $template))
            ->when(in_array($channel, ['email', 'whatsapp'], true), fn ($q) => $q->where('channel', $channel))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json(['notifications' => [
            'data' => $logs->getCollection()->map(fn (NotificationLog $log) => $this->row($log)),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
            ],
        ]]);
    }

    /**
     * One manual resend. 422 = refused before anything was sent (already
     * delivered, login_otp, unmapped template); 200 = an attempt was made
     * and the refreshed row in the response is the truth about how it went.
     */
    public function resend(Request $request, string $ulid): JsonResponse
    {
        $log = NotificationLog::where('ulid', $ulid)->firstOrFail();

        if ($reason = $this->retry->manualRefusal($log)) {
            throw new HttpResponseException(response()->json(['message' => $reason], 422));
        }

        $result = $this->retry->retryOne($log);

        ActivityLog::record($request->user(), 'notification.resent', $log, [
            'template' => $log->template,
            'recipient' => $log->recipient,
            'success' => $result->success,
        ]);

        return response()->json([
            'result' => ['success' => $result->success, 'message' => $result->message],
            'notification' => $this->row($log->fresh()),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(NotificationLog $log): array
    {
        return [
            'ulid' => $log->ulid,
            'channel' => $log->channel,
            'template' => $log->template,
            'recipient' => $log->recipient,
            'status' => $log->status,
            'attempts' => $log->attempts,
            'error' => mb_substr((string) $log->error, 0, 200),
            'sent_at' => $log->sent_at?->toIso8601String(),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
