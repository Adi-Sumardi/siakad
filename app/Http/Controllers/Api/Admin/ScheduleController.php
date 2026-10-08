<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreClassScheduleRequest;
use App\Http\Requests\Admin\UpdateClassScheduleRequest;
use App\Models\ActivityLog;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The lesson-period timetable admins fill in per classroom, so attendance sessions have something to open against. */
class ScheduleController extends Controller
{
    public function index(Request $request, string $classroomUlid): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();

        $schedules = $classroom->classSchedules()
            ->with('subject', 'teacher', 'classroom')
            ->orderBy('day_of_week')->orderBy('start_time')
            ->get();

        return response()->json([
            'classroom' => ['ulid' => $classroom->ulid, 'name' => $classroom->name],
            'schedules' => $schedules->map(fn (ClassSchedule $s) => $this->row($s)),
        ]);
    }

    /**
     * Every period of every active classroom the caller can see - the
     * "Semua kelas" view, where clashes and free periods across classes
     * show up. Scoping is ClassSchedule::visibleTo (the classroom's unit),
     * unchanged; `unit` only narrows a central admin's view further.
     */
    public function all(Request $request): JsonResponse
    {
        $schedules = ClassSchedule::visibleTo($request->user())
            ->whereHas('classroom', fn ($q) => $q->where('is_active', true)
                ->when($request->string('unit')->value(), fn ($q, $code) => $q->whereHas('schoolUnit', fn ($u) => $u->where('code', $code))))
            ->with('subject', 'teacher', 'classroom')
            ->orderBy('day_of_week')->orderBy('start_time')
            ->get();

        return response()->json([
            'schedules' => $schedules->map(fn (ClassSchedule $s) => $this->row($s)),
        ]);
    }

    private function row(ClassSchedule $s): array
    {
        return [
            'ulid' => $s->ulid,
            'classroom' => ['ulid' => $s->classroom->ulid, 'name' => $s->classroom->name, 'tingkat' => $s->classroom->tingkat],
            'subject' => ['ulid' => $s->subject->ulid, 'name' => $s->subject->name],
            'teacher' => $s->teacher ? ['ulid' => $s->teacher->ulid, 'name' => $s->teacher->name] : null,
            'day_of_week' => $s->day_of_week,
            'start_time' => substr((string) $s->start_time, 0, 5),
            'end_time' => substr((string) $s->end_time, 0, 5),
        ];
    }

    public function store(StoreClassScheduleRequest $request, string $classroomUlid): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();

        $validated = $request->validated();

        $subject = $this->resolveSubject($validated['subject_ulid'], $classroom);
        $teacher = $this->resolveTeacher($validated['teacher_ulid'] ?? null, $classroom);

        $this->assertNoClassroomClash(
            $classroom, $validated['day_of_week'], $validated['start_time'], $validated['end_time'],
        );
        if ($teacher) {
            $this->assertNoTeacherClash(
                $teacher, $validated['day_of_week'], $validated['start_time'], $validated['end_time'],
            );
        }

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

    public function update(UpdateClassScheduleRequest $request, string $classroomUlid, string $ulid): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();
        $schedule = $classroom->classSchedules()->where('ulid', $ulid)->firstOrFail();

        $validated = $request->validated();

        // The update is partial, so every check below runs against the MERGED
        // final slot - unset fields keep the schedule's current values - and
        // the schedule never clashes with itself.
        $teacher = array_key_exists('teacher_ulid', $validated)
            ? $this->resolveTeacher($validated['teacher_ulid'], $classroom)
            : $schedule->teacher;

        $day = (int) ($validated['day_of_week'] ?? $schedule->day_of_week);
        $start = substr((string) ($validated['start_time'] ?? $schedule->start_time), 0, 5);
        $end = substr((string) ($validated['end_time'] ?? $schedule->end_time), 0, 5);

        if ($end <= $start) {
            return response()->json(['message' => 'Jam selesai harus lebih besar dari jam mulai.'], 422);
        }

        $this->assertNoClassroomClash($classroom, $day, $start, $end, $schedule->id);
        if ($teacher) {
            $this->assertNoTeacherClash($teacher, $day, $start, $end, $schedule->id);
        }

        if (array_key_exists('teacher_ulid', $validated)) {
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

    /**
     * A teacher id that doesn't resolve - unknown ULID, or a ULID that
     * belongs to a non-guru - is a 422 naming the problem. The old
     * `first() ?: null` silently stored a teacher-less period, which is how
     * a class ends up "Bukan jadwal Anda" for every guru on the day (T25).
     * A guru from another unit is refused for the same reason the classroom
     * lookup is: the timetable is unit-scoped.
     */
    private function resolveTeacher(?string $ulid, Classroom $classroom): ?User
    {
        if (! $ulid) {
            return null; // an empty value is the explicit "belum ada guru" choice
        }

        $teacher = User::where('ulid', $ulid)->where('role', 'guru')->first();

        if (! $teacher) {
            throw new HttpResponseException(response()->json([
                'message' => 'Guru tidak ditemukan atau bukan role guru.',
            ], 422));
        }

        if ($teacher->school_unit_id !== $classroom->school_unit_id) {
            throw new HttpResponseException(response()->json([
                'message' => "Guru {$teacher->name} bukan dari unit kelas ini.",
            ], 422));
        }

        return $teacher;
    }

    /**
     * The subject must be one this classroom can actually take: from the
     * classroom's own unit (or school-wide), not merged away, active, and
     * running in the classroom's tingkat.
     */
    private function resolveSubject(string $ulid, Classroom $classroom): Subject
    {
        $subject = Subject::forUnit($classroom->school_unit_id)->notMerged()->where('ulid', $ulid)->with('tingkatRows')->first();

        if (! $subject) {
            throw new HttpResponseException(response()->json([
                'message' => 'Mata pelajaran tidak ditemukan untuk unit kelas ini.',
            ], 422));
        }

        if (! $subject->is_active) {
            throw new HttpResponseException(response()->json([
                'message' => "Mata pelajaran {$subject->name} sedang nonaktif.",
            ], 422));
        }

        if (! $subject->appliesToTingkat($classroom->tingkat)) {
            throw new HttpResponseException(response()->json([
                'message' => "Mata pelajaran {$subject->name} tidak berlaku untuk tingkat {$classroom->tingkat} (kelas {$classroom->name}).",
            ], 422));
        }

        return $subject;
    }

    /** Two periods of one classroom may touch (07:00-08:30 then 08:30-10:00) but never overlap. */
    private function assertNoClassroomClash(Classroom $classroom, int $day, string $start, string $end, ?int $ignoreId = null): void
    {
        $clash = $classroom->classSchedules()
            ->where('day_of_week', $day)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereRaw('substr(start_time, 1, 5) < ?', [$end])
            ->whereRaw('substr(end_time, 1, 5) > ?', [$start])
            ->with('subject')
            ->first();

        if ($clash) {
            throw new HttpResponseException(response()->json([
                'message' => sprintf(
                    'Bentrok dengan %s (%s-%s) di kelas %s pada hari yang sama.',
                    $clash->subject->name,
                    substr((string) $clash->start_time, 0, 5),
                    substr((string) $clash->end_time, 0, 5),
                    $classroom->name,
                ),
            ], 422));
        }
    }

    /** One teacher cannot stand in two rooms at once - checked across every classroom, since that is the point. */
    private function assertNoTeacherClash(User $teacher, int $day, string $start, string $end, ?int $ignoreId = null): void
    {
        $clash = ClassSchedule::query()
            ->where('teacher_id', $teacher->id)
            ->where('day_of_week', $day)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereRaw('substr(start_time, 1, 5) < ?', [$end])
            ->whereRaw('substr(end_time, 1, 5) > ?', [$start])
            ->with(['subject', 'classroom'])
            ->first();

        if ($clash) {
            throw new HttpResponseException(response()->json([
                'message' => sprintf(
                    'Guru %s sudah mengajar %s di kelas %s pada jam yang sama (%s-%s).',
                    $teacher->name,
                    $clash->subject->name,
                    $clash->classroom->name,
                    substr((string) $clash->start_time, 0, 5),
                    substr((string) $clash->end_time, 0, 5),
                ),
            ], 422));
        }
    }
}
