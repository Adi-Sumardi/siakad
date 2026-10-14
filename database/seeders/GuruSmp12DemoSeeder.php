<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Achievement;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\DailyAttendanceSetting;
use App\Models\DailyRecord;
use App\Models\Enrollment;
use App\Models\Extracurricular;
use App\Models\ExtracurricularMember;
use App\Models\Grade;
use App\Models\GradeAssignment;
use App\Models\Guardian;
use App\Models\LeaveRequest;
use App\Models\PointRecord;
use App\Models\PointRule;
use App\Models\SchoolUnit;
use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\Academic\GradeService;
use App\Services\Attendance\DailyAttendanceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Developer-only test data for the guru portal at SMPI Al Azhar 12
 * Rawamangun, centred on guru.smp-12.1@yapinet.id:
 *
 * - wali kelas of 7-A (12 students), so Presensi Harian, izin/sakit
 *   requests, prestasi verification and the class rapor all have rows;
 * - Matematika teacher of 7-A, 7-B, 8-A and 9-A, with a period in six of
 *   the seven slots every day Senin-Sabtu (07:00-17:00), so a lesson is
 *   "Sedang Berlangsung" whenever someone tests absen mata pelajaran;
 * - two weeks of lesson + daily attendance history, multi-assignment
 *   Matematika grades, a few points, pembina of Robotik.
 *
 * `php artisan db:seed --class=GuruSmp12DemoSeeder`
 *
 * Idempotent for a dev database: the four rooms' timetables (and their
 * lesson sessions) are rebuilt, everything else is keyed on natural keys.
 */
class GuruSmp12DemoSeeder extends Seeder
{
    private const ROOMS = ['7-A' => 7, '7-B' => 7, '8-A' => 8, '9-A' => 9];

    /** Seven 80-minute slots; guru 1 rests in slot 4 (index 3). */
    private const SLOTS = [
        ['07:00', '08:20'], ['08:20', '09:40'], ['10:00', '11:20'], ['11:20', '12:40'],
        ['13:00', '14:20'], ['14:20', '15:40'], ['15:40', '17:00'],
    ];

    private const FIRST = ['Muhammad', 'Aisyah', 'Rizky', 'Nafisa', 'Faris', 'Zahra', 'Hafizh', 'Keysha', 'Ilham', 'Khansa', 'Zaky', 'Salma'];

    private const LAST = ['Pratama', 'Ramadhan', 'Hidayat', 'Saputra', 'Maulana', 'Hakim', 'Nugraha', 'Wijaya'];

    public function run(): void
    {
        $unit = SchoolUnit::findByCode('SMP-12');
        $year = AcademicYear::current();
        $term = Term::current();

        if (! $unit || ! $year || ! $term) {
            $this->command?->warn('SMP-12, tahun ajaran aktif, atau semester aktif belum ada - jalankan DatabaseSeeder dulu.');

            return;
        }

        $teachers = $this->teachers($unit);
        $guru = $teachers['mtk'];

        StaffProfile::updateOrCreate(['user_id' => $guru->id], ['nip' => '198703122010012004', 'jabatan' => 'Guru Matematika']);

        $subjects = $this->subjects($unit);
        $rooms = $this->rooms($unit, $year, $teachers);
        $students = $this->students($unit, $year, $rooms);

        $schedules = $this->timetable($rooms, $subjects, $teachers);
        $this->lessonHistory($schedules->where('teacher_id', $guru->id), $rooms, $students, $term, $guru);
        $this->dailyHistory($unit, $rooms['7-A'], $students['7-A'], $term, $guru);
        $this->todayGate($unit, $students, $rooms, $term);
        $this->grades($rooms, $students, $subjects['Matematika'], $term, $guru);
        $this->homeroomExtras($unit, $students['7-A'], $term, $guru);
        $this->ekskul($unit, $year, $students, $guru);

        $this->command?->info("Data uji SMP-12 siap: {$guru->email} (wali 7-A, Matematika 7-A/7-B/8-A/9-A).");
    }

    /** @return array<string, User> mtk = the account under test; the rest teach the other subjects */
    private function teachers(SchoolUnit $unit): array
    {
        $make = fn (int $i) => User::updateOrCreate(
            ['email' => "guru.smp-12.{$i}@yapinet.id"],
            [
                'name' => "Guru {$unit->label} {$i}",
                'role' => 'guru',
                'school_unit_id' => $unit->id,
                'is_active' => true,
                'activated_at' => now(),
                'email_verified_at' => now(),
            ],
        );

        return ['mtk' => $make(1), 'PAI' => $make(2), 'IPA' => $make(3), 'Bahasa Indonesia' => $make(4), 'Bahasa Inggris' => $make(5)];
    }

    /** @return array<string, Subject> by name - reuses the unit's (or the school-wide) row before creating one */
    private function subjects(SchoolUnit $unit): array
    {
        $out = [];
        foreach (['Matematika', 'PAI', 'IPA', 'Bahasa Indonesia', 'Bahasa Inggris'] as $name) {
            $out[$name] = Subject::whereNull('merged_into_id')
                ->where('name', $name)
                ->where(fn ($q) => $q->where('school_unit_id', $unit->id)->orWhereNull('school_unit_id'))
                ->orderByRaw('school_unit_id is null')
                ->first()
                ?? Subject::create(['school_unit_id' => $unit->id, 'code' => 'SMP12-'.Str::upper(Str::slug($name)), 'name' => $name, 'is_active' => true]);
        }

        return $out;
    }

    /** @return array<string, Classroom> */
    private function rooms(SchoolUnit $unit, AcademicYear $year, array $teachers): array
    {
        $wali = ['7-A' => $teachers['mtk'], '7-B' => $teachers['PAI'], '8-A' => $teachers['IPA'], '9-A' => $teachers['Bahasa Indonesia']];

        $rooms = [];
        foreach (self::ROOMS as $name => $tingkat) {
            $room = Classroom::firstOrCreate(
                ['academic_year_id' => $year->id, 'school_unit_id' => $unit->id, 'name' => $name],
                ['tingkat' => $tingkat, 'capacity' => 32, 'is_active' => true],
            );
            $room->update(['homeroom_teacher_id' => $wali[$name]->id, 'is_active' => true]);
            $rooms[$name] = $room;
        }

        return $rooms;
    }

    /** Tops every room up to 12 active students; whoever is already enrolled stays. @return array<string, Collection<int, Student>> */
    private function students(SchoolUnit $unit, AcademicYear $year, array $rooms): array
    {
        $out = [];
        $roomIndex = 0;

        foreach ($rooms as $name => $room) {
            $roomIndex++;
            for ($n = 1; $n <= 12; $n++) {
                if ($room->enrollments()->where('status', 'active')->count() >= 12) {
                    break;
                }

                $nis = sprintf('2612%d%d%02d', $room->tingkat, $roomIndex, $n);
                $seq = $roomIndex * 12 + $n;
                $student = Student::updateOrCreate(
                    ['nis' => $nis],
                    [
                        'school_unit_id' => $unit->id,
                        'entry_year_id' => $year->id,
                        'nama_lengkap' => self::FIRST[$n - 1].' '.self::LAST[$seq % count(self::LAST)],
                        'nama_panggilan' => '',
                        'jenis_kelamin' => $n % 2 ? 'L' : 'P',
                        'status' => 'active',
                    ],
                );

                Enrollment::updateOrCreate(
                    ['student_id' => $student->id, 'academic_year_id' => $year->id],
                    ['classroom_id' => $room->id, 'status' => 'active', 'joined_on' => $year->starts_on],
                );
            }

            $out[$name] = $room->enrollments()->where('status', 'active')->with('student')->get()
                ->pluck('student')->filter()->sortBy('nama_lengkap')->values();
        }

        return $out;
    }

    /**
     * Senin-Sabtu x 7 slots. Guru 1 rotates through the four rooms in every
     * slot but the fourth; the other rooms in that slot get the other four
     * teachers, each in one room only - nobody is in two places at once.
     *
     * @return Collection<int, ClassSchedule>
     */
    private function timetable(array $rooms, array $subjects, array $teachers): Collection
    {
        $roomIds = collect($rooms)->pluck('id');
        $old = ClassSchedule::whereIn('classroom_id', $roomIds)->pluck('id');
        AttendanceSession::whereIn('class_schedule_id', $old)->delete();
        ClassSchedule::whereIn('id', $old)->delete();

        $others = ['PAI', 'IPA', 'Bahasa Indonesia', 'Bahasa Inggris'];
        $names = array_keys($rooms);
        $created = collect();

        foreach (range(1, 6) as $day) {
            foreach (self::SLOTS as $k => [$start, $end]) {
                $mtkRoom = $k === 3 ? null : $names[($k + $day) % 4];

                $j = 0;
                foreach ($names as $name) {
                    if ($name === $mtkRoom) {
                        $subject = $subjects['Matematika'];
                        $teacher = $teachers['mtk'];
                    } else {
                        $other = $others[($j + $k + $day) % 4];
                        $subject = $subjects[$other];
                        $teacher = $teachers[$other];
                        $j++;
                    }

                    $created->push(ClassSchedule::create([
                        'classroom_id' => $rooms[$name]->id,
                        'subject_id' => $subject->id,
                        'teacher_id' => $teacher->id,
                        'day_of_week' => $day,
                        'start_time' => $start.':00',
                        'end_time' => $end.':00',
                    ]));
                }
            }
        }

        return $created;
    }

    /** Closed lesson sessions for guru 1's periods over the last two weeks (today stays untouched for live testing). */
    private function lessonHistory(Collection $schedules, array $rooms, array $students, Term $term, User $guru): void
    {
        $roomNameById = collect($rooms)->mapWithKeys(fn (Classroom $room, string $name) => [$room->id => $name]);

        foreach ($this->pastSchoolDays(10, $term) as $date) {
            foreach ($schedules->where('day_of_week', $date->dayOfWeekIso) as $schedule) {
                $roomName = $roomNameById[$schedule->classroom_id];

                $session = AttendanceSession::create([
                    'class_schedule_id' => $schedule->id,
                    'occurred_on' => $date->toDateString(),
                    'token' => 'demo-'.Str::random(40),
                    'status' => 'closed',
                    'opened_by' => $guru->id,
                    'opened_at' => $date->copy()->setTimeFromTimeString((string) $schedule->start_time),
                    'closed_at' => $date->copy()->setTimeFromTimeString((string) $schedule->end_time),
                    'expires_at' => $date->copy()->setTimeFromTimeString((string) $schedule->end_time),
                ]);

                foreach ($students[$roomName] as $student) {
                    AttendanceRecord::create([
                        'student_id' => $student->id,
                        'attendance_session_id' => $session->id,
                        'classroom_id' => $schedule->classroom_id,
                        'term_id' => $term->id,
                        'attendance_status' => $this->statusFor($student->id, $date),
                        'occurred_on' => $date->toDateString(),
                        'source' => 'guru',
                        'recorded_by' => $guru->id,
                        'record_status' => 'recorded',
                    ]);
                }
            }
        }
    }

    /** Daily (masuk) history for the homeroom - what the class recap and Presensi Harian corrections read. */
    private function dailyHistory(SchoolUnit $unit, Classroom $room, Collection $students, Term $term, User $guru): void
    {
        $service = app(DailyAttendanceService::class);
        $setting = $service->ensureSettings($unit);
        // On, but silent: a dev database must never fire WA/email at real numbers.
        $setting->update(['enabled' => true, 'notify_masuk' => false, 'notify_pulang' => false, 'notify_absent' => false]);
        $setting = DailyAttendanceSetting::find($setting->id);

        foreach ($this->pastSchoolDays(10, $term) as $date) {
            $session = $service->ensureSessionsForDate($setting, $date)->firstWhere('type', 'masuk');
            if (! $session) {
                continue;
            }
            $session->update(['status' => 'closed', 'closed_at' => $date->copy()->setTime(8, 0)]);

            DailyRecord::where('daily_session_id', $session->id)->whereIn('student_id', $students->pluck('id'))->delete();

            foreach ($students as $student) {
                $status = $this->statusFor($student->id, $date);
                $late = $status === 'hadir' && ($student->id + $date->day) % 9 === 0;

                DailyRecord::create([
                    'daily_session_id' => $session->id,
                    'student_id' => $student->id,
                    'classroom_id' => $room->id,
                    'term_id' => $term->id,
                    'date' => $date->toDateString(),
                    'attendance_status' => $status,
                    'is_late' => $late,
                    'source' => 'wali_kelas',
                    'checked_in_at' => $status === 'hadir' ? $date->copy()->setTime(6, $late ? 59 : 40)->addMinutes($late ? 25 : 0) : null,
                    'recorded_by' => $guru->id,
                    'record_status' => 'recorded',
                ]);
            }
        }
    }

    /**
     * Today's gate check-ins, so a lesson roster opens pre-filled from the
     * gate: 7-B/8-A/9-A fully scanned (one sakit each), 7-A only partly -
     * its last four students are left for the wali kelas to mark on
     * Presensi Harian. Re-seeding resets today's marks for these students.
     */
    private function todayGate(SchoolUnit $unit, array $students, array $rooms, Term $term): void
    {
        $service = app(DailyAttendanceService::class);
        $setting = $service->ensureSettings($unit);
        $today = Carbon::now('Asia/Jakarta')->startOfDay();

        $session = $service->ensureSessionsForDate($setting, $today)->firstWhere('type', 'masuk');
        if (! $session) {
            return; // weekend, holiday, or the unit's days exclude today
        }

        foreach ($rooms as $name => $room) {
            $list = $students[$name]->values();
            DailyRecord::where('daily_session_id', $session->id)->whereIn('student_id', $list->pluck('id'))->delete();

            $scanned = $name === '7-A' ? $list->take($list->count() - 4) : $list;
            foreach ($scanned as $i => $student) {
                $sick = $i === 1 && $name !== '7-A';
                DailyRecord::create([
                    'daily_session_id' => $session->id,
                    'student_id' => $student->id,
                    'classroom_id' => $room->id,
                    'term_id' => $term->id,
                    'date' => $today->toDateString(),
                    'attendance_status' => $sick ? 'sakit' : 'hadir',
                    'is_late' => ! $sick && $i === 4,
                    'source' => $sick ? 'tu' : 'self',
                    // Late = after the unit's 07:15 masuk_late_after default.
                    'checked_in_at' => $sick ? null : ($i === 4 ? $today->copy()->setTime(7, 22) : $today->copy()->setTime(6, 35 + $i * 2)),
                    'record_status' => 'recorded',
                ]);
            }
        }
    }

    /**
     * Matematika in all four rooms: 7-A has three assignments + UTS (UAS not
     * yet - mid-semester), the others two assignments, some cells still
     * empty so the sheet has something left to fill.
     */
    private function grades(array $rooms, array $students, Subject $mtk, Term $term, User $guru): void
    {
        $service = app(GradeService::class);

        foreach ($rooms as $name => $room) {
            GradeAssignment::where('classroom_id', $room->id)->where('subject_id', $mtk->id)->where('term_id', $term->id)->delete();
            Grade::where('classroom_id', $room->id)->where('subject_id', $mtk->id)->where('term_id', $term->id)->delete();

            $titles = $name === '7-A'
                ? [['Tugas 1 - Bilangan Bulat', '2026-07-21'], ['Tugas 2 - Pecahan', '2026-08-11'], ['Tugas 3 - Aljabar', '2026-09-08']]
                : [['Tugas 1', '2026-07-23'], ['Tugas 2', '2026-08-20']];

            $assignments = collect($titles)->map(fn ($t) => $service->createAssignment($room, $mtk, $term, $t[0], $t[1], $guru));

            $entries = $students[$name]->values()->map(function (Student $student, int $i) use ($assignments, $name) {
                mt_srand($student->id * 13 + 7);
                $base = mt_rand(1, 6) === 1 ? mt_rand(50, 65) : mt_rand(68, 95); // a few below KKM

                $tugas = [];
                foreach ($assignments as $a => $assignment) {
                    // The last assignment is still being marked for a few students.
                    if ($a === $assignments->count() - 1 && $i % 4 === 3) {
                        continue;
                    }
                    $tugas[$assignment->id] = (float) min(100, max(40, $base + mt_rand(-8, 8)));
                }

                $entry = ['student' => $student, 'tugas' => $tugas];
                if ($name === '7-A') {
                    $entry['uts'] = (float) min(100, max(40, $base + mt_rand(-6, 6)));
                }

                return $entry;
            });

            $service->saveSheet($room, $mtk, $term, $entries, $guru);
        }
    }

    /** Things only a wali kelas acts on: pending izin/sakit, a pending prestasi, a few points. */
    private function homeroomExtras(SchoolUnit $unit, Collection $students, Term $term, User $guru): void
    {
        $parentUser = User::updateOrCreate(
            ['email' => 'wali.smp12.demo@yapinet.id'],
            ['name' => 'Bapak Hendra Saputra', 'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(), 'email_verified_at' => now()],
        );
        $guardian = Guardian::updateOrCreate(
            ['user_id' => $parentUser->id],
            ['nama' => 'Hendra Saputra', 'hubungan' => 'ayah', 'email' => 'wali.smp12.demo@yapinet.id'],
        );

        $kids = $students->take(2);
        foreach ($kids as $kid) {
            $kid->guardians()->syncWithoutDetaching([$guardian->id => ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]]);
        }

        $today = Carbon::now('Asia/Jakarta')->startOfDay();
        $requests = [
            [$kids[0], 'sakit', $today, $today, 'Demam sejak semalam, sudah ke dokter. Surat dokter menyusul.'],
            [$kids[1], 'izin', $today->copy()->addDay(), $today->copy()->addDays(2), 'Menghadiri pernikahan kakak di Bandung.'],
        ];
        foreach ($requests as [$kid, $type, $from, $to, $reason]) {
            LeaveRequest::where('student_id', $kid->id)->where('status', 'pending')->delete();
            LeaveRequest::create([
                'student_id' => $kid->id, 'school_unit_id' => $unit->id, 'requested_by' => $parentUser->id,
                'type' => $type, 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(),
                'reason' => $reason, 'status' => 'pending',
            ]);
        }

        $achiever = $students[2];
        Achievement::updateOrCreate(
            ['student_id' => $achiever->id, 'nama_prestasi' => 'Juara 2 Olimpiade Matematika SMP'],
            [
                'achiever_type' => 'siswa', 'school_unit_id' => $unit->id, 'kategori' => 'Akademik',
                'tingkat' => 'Kabupaten/Kota', 'juara' => '2', 'nama_event' => 'OSN Matematika Tingkat Kota',
                'penyelenggara' => 'Sudin Pendidikan Jakarta Timur', 'tanggal_event' => '2026-10-03',
                'source' => 'sekolah', 'status' => 'pending', 'recorded_by' => $parentUser->id,
            ],
        );

        $rules = PointRule::whereNull('school_unit_id')->orWhere('school_unit_id', $unit->id)->get()->keyBy('code');
        $marks = [[$students[3], 'terlambat', 2], [$students[3], 'terlambat', 6], [$students[5], 'seragam', 3], [$students[6], 'inisiatif', 4]];
        foreach ($marks as [$student, $code, $daysAgo]) {
            $rule = $rules->get($code);
            if (! $rule) {
                continue;
            }
            PointRecord::firstOrCreate(
                ['student_id' => $student->id, 'term_id' => $term->id, 'point_rule_id' => $rule->id, 'occurred_on' => $today->copy()->subDays($daysAgo)->toDateString()],
                ['type' => $rule->type, 'points' => $rule->points, 'description' => $rule->name, 'recorded_by' => $guru->id, 'status' => 'recorded'],
            );
        }
    }

    private function ekskul(SchoolUnit $unit, AcademicYear $year, array $students, User $guru): void
    {
        $robotik = Extracurricular::updateOrCreate(
            ['school_unit_id' => $unit->id, 'academic_year_id' => $year->id, 'name' => 'Robotik'],
            ['description' => 'Merakit dan memprogram robot sederhana', 'pembina_id' => $guru->id, 'capacity' => 20, 'is_active' => true],
        );

        $members = collect($students)->flatMap(fn ($list) => $list->take(2));
        foreach ($members as $student) {
            ExtracurricularMember::firstOrCreate(
                ['extracurricular_id' => $robotik->id, 'student_id' => $student->id, 'academic_year_id' => $year->id],
                ['status' => 'active', 'joined_on' => $year->starts_on, 'assigned_at' => now()],
            );
        }
    }

    /** Mostly hadir, a sprinkle of sakit/izin/alpa - deterministic so re-seeding gives the same picture. */
    private function statusFor(int $studentId, Carbon $date): string
    {
        $key = ($studentId * 7 + $date->dayOfYear * 3) % 31;

        return match (true) {
            $key === 0 => 'alpa',
            $key === 5 => 'sakit',
            $key === 11 => 'izin',
            default => 'hadir',
        };
    }

    /** @return Collection<int, Carbon> the last $n Senin-Jumat before today, inside the running term */
    private function pastSchoolDays(int $n, Term $term): Collection
    {
        $days = collect();
        $cursor = Carbon::now('Asia/Jakarta')->startOfDay()->subDay();
        $termStart = Carbon::parse($term->starts_on, 'Asia/Jakarta')->startOfDay();

        while ($days->count() < $n && $cursor->gte($termStart)) {
            if ($cursor->dayOfWeekIso <= 5) {
                $days->push($cursor->copy());
            }
            $cursor->subDay();
        }

        return $days;
    }
}
