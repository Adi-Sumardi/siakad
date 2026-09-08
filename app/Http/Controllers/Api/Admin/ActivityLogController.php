<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only window over activity_logs - the rows every money and points
 * action has been writing all along, previously visible only by opening the
 * database itself. Read-only on purpose: the log's value is that nobody, this
 * viewer included, can edit it.
 *
 * Central admin only for now. The table has no school_unit_id, so scoping an
 * admin_unit to "their" rows would mean resolving each subject model's unit -
 * easy to get subtly wrong, and a leak here is a leak about other people's
 * children. Widening to admin_unit is a deliberate decision, not a TODO.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = ActivityLog::query()
            // Substring on action doubles as a category filter: "point."
            // matches point.recorded, point.revoked, point_rule.*, ...
            ->when($request->string('action')->value(), fn ($q, $action) => $q
                ->where('action', 'like', "%{$action}%"))
            ->when($request->string('user')->value(), fn ($q, $name) => $q
                ->whereIn('user_id', User::where('name', 'like', "%{$name}%")->pluck('id')))
            ->when($request->string('from')->value(), fn ($q, $from) => $q
                ->where('created_at', '>=', $from.' 00:00:00'))
            ->when($request->string('to')->value(), fn ($q, $to) => $q
                ->where('created_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50);

        $rows = $logs->getCollection()->load('user:id,ulid,name,role');
        $subjectUlids = $this->subjectUlids($rows);

        return response()->json(['logs' => [
            'data' => $rows->map(fn (ActivityLog $log) => [
                'ulid' => $log->ulid,
                'user' => $log->user?->only(['ulid', 'name', 'role']),
                'action' => $log->action,
                'subject_type' => $log->subject_type ? class_basename($log->subject_type) : null,
                'subject_ulid' => $log->subject_type
                    ? ($subjectUlids[$log->subject_type][$log->subject_id] ?? null)
                    : null,
                'meta' => $log->meta,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]]);
    }

    /**
     * subject_id is the numeric internal key (that is what ::record stores),
     * but nothing numeric leaves the API - resolve each page's subjects to
     * their ULIDs in one query per subject type. Deleted subjects simply map
     * to null and render as "—"; the log row itself stays.
     *
     * @param  \Illuminate\Support\Collection<ActivityLog>  $logs
     * @return array<class-string, array<int, ?string>>
     */
    private function subjectUlids($logs): array
    {
        $idsByType = [];

        foreach ($logs as $log) {
            if ($log->subject_type && class_exists($log->subject_type) && is_a($log->subject_type, \Illuminate\Database\Eloquent\Model::class, true)) {
                $idsByType[$log->subject_type][] = $log->subject_id;
            }
        }

        $map = [];

        foreach ($idsByType as $type => $ids) {
            // Not every table has a ulid column (the known holdout is
            // payment_allocations); those resolve to nothing rather than
            // breaking the page.
            try {
                $map[$type] = $type::query()->whereKey(array_unique($ids))->pluck('ulid', 'id')->all();
            } catch (\Throwable) {
                $map[$type] = [];
            }
        }

        return $map;
    }
}
