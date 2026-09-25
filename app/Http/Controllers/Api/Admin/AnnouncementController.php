<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAnnouncementRequest;
use App\Http\Requests\Admin\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Classroom;
use App\Models\SchoolUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $announcements = Announcement::query()
            ->visibleTo($request->user())
            ->with(['schoolUnit', 'classroom', 'createdBy', 'targets'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['announcements' => AnnouncementResource::collection($announcements)]);
    }

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $validated = $request->validated();

        [$unit, $classroom] = $this->resolveScope($request, $validated);

        // Jenjang targeting is a central-admin move (Poin 8): a per-unit
        // admin's audience is already their own unit - cross-unit rungs
        // would reach families outside their authority.
        if (! empty($validated['jenjang']) && $request->user()->isUnitScoped()) {
            return response()->json([
                'message' => 'Penargetan jenjang hanya untuk admin pusat - gunakan cakupan unit untuk pengumuman unit Anda.',
            ], 422);
        }

        $file = $request->hasFile('file') ? $request->file('file') : null;

        $announcement = Announcement::create([
            'school_unit_id' => $unit?->id,
            'classroom_id' => $classroom?->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'file_path' => $file?->store('announcements', 'local'),
            'file_name' => $file?->getClientOriginalName(),
            'file_size' => $file?->getSize(),
            'is_pinned' => $validated['is_pinned'] ?? false,
            // Publishing now unless the admin scheduled it for later - the
            // controller decides this, never a raw published flag off the request.
            'published_at' => $validated['published_at'] ?? now(),
            'created_by' => $request->user()->id,
        ]);

        $this->syncJenjangTargets($announcement, $validated['jenjang'] ?? null);

        ActivityLog::record($request->user(), 'announcement.created', $announcement, ['title' => $announcement->title]);

        return response()->json(['announcement' => new AnnouncementResource($announcement->load('targets'))], 201);
    }

    /**
     * Replace-safely sync of the ladder targets: null = untouched (the
     * `sometimes` contract), an array = exactly that set, [] = cleared.
     */
    private function syncJenjangTargets(Announcement $announcement, ?array $jenjang): void
    {
        if ($jenjang === null) {
            return;
        }

        $announcement->targets()->where('kind', 'jenjang')->delete();

        foreach (array_values($jenjang) as $value) {
            $announcement->targets()->create(['kind' => 'jenjang', 'value' => $value]);
        }
    }

    public function update(UpdateAnnouncementRequest $request, string $ulid): JsonResponse
    {
        $announcement = Announcement::manageableBy($request->user())->where('ulid', $ulid)->firstOrFail();

        $validated = $request->validated();

        if (! empty($validated['jenjang']) && $request->user()->isUnitScoped()) {
            return response()->json([
                'message' => 'Penargetan jenjang hanya untuk admin pusat.',
            ], 422);
        }

        $announcement->update(collect($validated)->except(['jenjang'])->all());

        $this->syncJenjangTargets($announcement, $validated['jenjang'] ?? null);

        ActivityLog::record($request->user(), 'announcement.updated', $announcement, collect($validated)->except(['jenjang'])->all());

        return response()->json(['announcement' => $announcement->fresh('targets')]);
    }

    public function destroy(Request $request, string $ulid): JsonResponse
    {
        $announcement = Announcement::manageableBy($request->user())->where('ulid', $ulid)->firstOrFail();

        ActivityLog::record($request->user(), 'announcement.deleted', $announcement, ['title' => $announcement->title]);
        $announcement->delete();

        return response()->json(['message' => 'Pengumuman dihapus.']);
    }

    /** @return array{0: ?SchoolUnit, 1: ?Classroom} */
    private function resolveScope(Request $request, array $validated): array
    {
        $user = $request->user();

        if ($user->isUnitScoped()) {
            // Forced to their own unit, never trusted from the request - the
            // same rule BillingRunController enforces for who a billing run
            // actually touches.
            $unit = $user->schoolUnit;
            $classroom = ! empty($validated['classroom_ulid'])
                ? Classroom::where('ulid', $validated['classroom_ulid'])->where('school_unit_id', $unit?->id)->first()
                : null;

            return [$unit, $classroom];
        }

        $unit = ! empty($validated['school_unit_code']) ? SchoolUnit::findByCode($validated['school_unit_code']) : null;
        $classroom = ! empty($validated['classroom_ulid']) ? Classroom::where('ulid', $validated['classroom_ulid'])->first() : null;

        // A classroom carries its own unit (audit T67-h): letting an
        // arbitrary unit code sit beside ANOTHER unit's classroom stored a
        // scope no reader honoured - staff of the written unit never saw the
        // announcement while the classroom's families did, and a classroom
        // without a unit read school-wide to staff but single-class to
        // families. The classroom always wins; a contradiction is refused
        // rather than silently resolved.
        if ($classroom) {
            if ($unit && $classroom->school_unit_id !== $unit->id) {
                abort(response()->json([
                    'message' => "Kelas {$classroom->name} bukan bagian dari {$unit->label} - pilih kelas dari unit yang sama.",
                ], 422));
            }

            $unit = $classroom->schoolUnit;
        }

        return [$unit, $classroom];
    }
}
