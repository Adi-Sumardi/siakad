<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
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
            ->with(['schoolUnit', 'classroom', 'createdBy'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['announcements' => AnnouncementResource::collection($announcements)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            'school_unit_code' => 'nullable|exists:school_units,code',
            'classroom_ulid' => 'nullable|string',
            'is_pinned' => 'boolean',
            'published_at' => 'nullable|date',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        [$unit, $classroom] = $this->resolveScope($request, $validated);

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

        ActivityLog::record($request->user(), 'announcement.created', $announcement, ['title' => $announcement->title]);

        return response()->json(['announcement' => new AnnouncementResource($announcement)], 201);
    }

    public function update(Request $request, string $ulid): JsonResponse
    {
        $announcement = Announcement::manageableBy($request->user())->where('ulid', $ulid)->firstOrFail();

        $validated = $request->validate([
            'title' => 'sometimes|string|max:200',
            'body' => 'sometimes|string|max:5000',
            'is_pinned' => 'boolean',
            'published_at' => 'nullable|date',
        ]);

        $announcement->update($validated);

        ActivityLog::record($request->user(), 'announcement.updated', $announcement, $validated);

        return response()->json(['announcement' => $announcement->fresh()]);
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

        return [$unit, $classroom];
    }
}
