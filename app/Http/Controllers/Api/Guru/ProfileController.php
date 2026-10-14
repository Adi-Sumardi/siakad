<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guru\UpdateProfileRequest;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\ClassSchedule;
use App\Models\Classroom;
use App\Models\Extracurricular;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The teacher's own page: who they are on record, and what they are
 * responsible for this year - homeroom, the weekly timetable, ekskul they
 * coach. Login contacts (email/phone) are read-only here: they are the OTP
 * channel, and changing them is the admin's job (an invitation flow), not
 * a form field.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()));
    }

    /** NIP and jabatan are the teacher's own staff record - nobody else maintains them today. */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $profile = StaffProfile::firstOrNew(['user_id' => $user->id]);
        $profile->fill([
            'nip' => $validated['nip'] ?? null,
            'jabatan' => $validated['jabatan'] ?? null,
        ]);
        $profile->save();

        ActivityLog::record($user, 'guru.profile_updated', null, ['fields' => ['nip', 'jabatan']]);

        return response()->json($this->payload($user->fresh()));
    }

    private function payload(User $user): array
    {
        $user->loadMissing('schoolUnit', 'staffProfile');
        $yearId = AcademicYear::current()?->id;

        $homeroom = Classroom::where('homeroom_teacher_id', $user->id)
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->withCount(['enrollments as student_count' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('tingkat')->orderBy('name')
            ->get();

        $schedules = ClassSchedule::where('teacher_id', $user->id)
            ->when($yearId, fn ($q) => $q->whereHas('classroom', fn ($c) => $c->where('academic_year_id', $yearId)))
            ->with('classroom', 'subject')
            ->orderBy('day_of_week')->orderBy('start_time')
            ->get();

        $ekskul = Extracurricular::where('pembina_id', $user->id)
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->withCount(['activeMembers as member_count'])
            ->orderBy('name')
            ->get();

        return [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'unit' => $user->schoolUnit?->label,
                'last_login_at' => $user->last_login_at,
                'activated_at' => $user->activated_at,
            ],
            'staff_profile' => [
                'nip' => $user->staffProfile?->nip,
                'jabatan' => $user->staffProfile?->jabatan,
            ],
            'academic_year' => AcademicYear::current()?->year,
            'homeroom' => $homeroom->map(fn (Classroom $c) => [
                'ulid' => $c->ulid, 'name' => $c->name, 'student_count' => (int) $c->student_count,
            ]),
            'teaching' => $schedules
                ->groupBy(fn (ClassSchedule $s) => $s->classroom_id.'-'.$s->subject_id)
                ->map(fn ($rows) => [
                    'classroom' => ['ulid' => $rows->first()->classroom->ulid, 'name' => $rows->first()->classroom->name],
                    'subject' => ['ulid' => $rows->first()->subject->ulid, 'name' => $rows->first()->subject->name],
                    'periods_per_week' => $rows->count(),
                ])
                ->sortBy(fn ($r) => $r['classroom']['name'].' '.$r['subject']['name'])
                ->values(),
            'weekly_schedule' => $schedules->map(fn (ClassSchedule $s) => [
                'day_of_week' => $s->day_of_week,
                'start_time' => substr((string) $s->start_time, 0, 5),
                'end_time' => substr((string) $s->end_time, 0, 5),
                'classroom' => ['ulid' => $s->classroom->ulid, 'name' => $s->classroom->name],
                'subject' => $s->subject->name,
            ])->values(),
            'extracurriculars' => $ekskul->map(fn (Extracurricular $e) => [
                'ulid' => $e->ulid, 'name' => $e->name, 'member_count' => (int) $e->member_count,
            ]),
        ];
    }
}
