<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The lesson-period timetable admins fill in per classroom, so attendance sessions have something to open against. */
class ScheduleController extends Controller
{
    public function index(Request $request, string $classroomUlid): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();

        $schedules = $classroom->classSchedules()
            ->with('subject', 'teacher')
            ->orderBy('day_of_week')->orderBy('start_time')
            ->get();

        return response()->json([
            'classroom' => ['ulid' => $classroom->ulid, 'name' => $classroom->name],
            'schedules' => $schedules->map(fn (ClassSchedule $s) => [
                'ulid' => $s->ulid,
                'subject' => ['ulid' => $s->subject->ulid, 'name' => $s->subject->name],
                'teacher' => $s->teacher ? ['ulid' => $s->teacher->ulid, 'name' => $s->teacher->name] : null,
                'day_of_week' => $s->day_of_week,
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
            ]),
        ]);
    }

    public function store(Request $request, string $classroomUlid): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();

        $validated = $request->validate([
            'subject_ulid' => 'required|string',
            'teacher_ulid' => 'nullable|string',
            'day_of_week' => 'required|integer|min:1|max:6',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        $subject = Subject::where('ulid', $validated['subject_ulid'])->firstOrFail();
        $teacher = isset($validated['teacher_ulid'])
            ? User::where('ulid', $validated['teacher_ulid'])->where('role', 'guru')->first()
            : null;

        $schedule = ClassSchedule::create([
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher?->id,
            'day_of_week' => $validated['day_of_week'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
        ]);

        ActivityLog::record($request->user(), 'class_schedule.created', $schedule, [
            'classroom' => $classroom->name, 'subject' => $subject->name,
        ]);

        return response()->json(['schedule' => $schedule], 201);
    }

    public function update(Request $request, string $classroomUlid, string $ulid): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();
        $schedule = $classroom->classSchedules()->where('ulid', $ulid)->firstOrFail();

        $validated = $request->validate([
            'teacher_ulid' => 'nullable|string',
            'day_of_week' => 'sometimes|integer|min:1|max:6',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
        ]);

        if (array_key_exists('teacher_ulid', $validated)) {
            $teacher = $validated['teacher_ulid']
                ? User::where('ulid', $validated['teacher_ulid'])->where('role', 'guru')->first()
                : null;
            $schedule->teacher_id = $teacher?->id;
        }

        $schedule->fill(collect($validated)->only(['day_of_week', 'start_time', 'end_time'])->all());
        $schedule->save();

        ActivityLog::record($request->user(), 'class_schedule.updated', $schedule, $validated);

        return response()->json(['schedule' => $schedule->fresh()]);
    }

    public function destroy(Request $request, string $classroomUlid, string $ulid): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();
        $schedule = $classroom->classSchedules()->where('ulid', $ulid)->firstOrFail();

        if ($schedule->attendanceSessions()->exists()) {
            // A schedule that has already had roll call taken against it stays
            // on file - deleting it would orphan real attendance history.
            return response()->json([
                'message' => 'Jadwal ini sudah pernah dipakai untuk presensi. Tidak bisa dihapus.',
            ], 422);
        }

        ActivityLog::record($request->user(), 'class_schedule.deleted', $schedule, []);
        $schedule->delete();

        return response()->json(['message' => 'Jadwal dihapus.']);
    }
}
