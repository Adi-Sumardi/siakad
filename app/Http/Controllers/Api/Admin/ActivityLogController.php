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
            ->when($request->string('user')->value(), fn ($q, $name) => $q->where(fn ($w) => $w
                ->where('user_name', 'like', "%{$name}%")
                ->orWhereIn('user_id', User::where('name', 'like', "%{$name}%")->pluck('id'))))
            // 2026-10-01: role, unit ("pusat" = no unit), category, outcome.
            ->when($request->string('role')->value(), fn ($q, $role) => $q->where('role', $role))
            ->when($request->string('unit')->value(), fn ($q, $unit) => $unit === 'pusat'
                ? $q->whereNull('school_unit_id')
                : $q->where('school_unit_id', (int) $unit))
            ->when($request->string('category')->value(), fn ($q, $c) => $q->where('category', $c))
            ->when($request->string('status')->value(), fn ($q, $st) => $st === 'failed'
                ? $q->where('status', '>=', 400)
                : $q->where(fn ($w) => $w->whereNull('status')->orWhere('status', '<', 400)))
            ->when($request->string('from')->value(), fn ($q, $from) => $q
                ->where('created_at', '>=', $from.' 00:00:00'))
            ->when($request->string('to')->value(), fn ($q, $to) => $q
                ->where('created_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        $rows = $logs->getCollection()->load('user:id,ulid,name,role');
        $subjectUlids = $this->subjectUlids($rows);

        return response()->json(['logs' => [
            'data' => $rows->map(fn (ActivityLog $log) => [
                'ulid' => $log->ulid,
                'user' => $log->user?->only(['ulid', 'name', 'role']),
                // As they were when it happened (see the 2026_10_01 migration).
                'user_name' => $log->user_name ?? $log->user?->name,
                'role' => $log->role ?? $log->user?->role,
                'unit' => $log->unit_label,
                'action' => $log->action,
                'label' => $log->label ?? \App\Support\ActivityCatalog::describe($log->action)['label'],
                'category' => $log->category ?? 'Lainnya',
                'status' => $log->status,
                'success' => $log->status === null || $log->status < 400,
                'subject_type' => $log->subject_type ? class_basename($log->subject_type) : null,
                'subject_ulid' => $log->subject_type
                    ? ($subjectUlids[$log->subject_type][$log->subject_id] ?? null)
                    : null,
                'meta' => $log->meta,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'options' => [
                'units' => \App\Models\SchoolUnit::orderBy('label')->get(['id', 'label']),
                'categories' => \App\Support\ActivityCatalog::categories(),
            ],
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
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
