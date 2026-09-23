<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Achievement;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Bill;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Extracurricular;
use App\Models\ExtracurricularMember;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\PointRecord;
use App\Models\PointRule;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Developer-only dummy data for the executive dashboard (Ringkasan).
 *
 * `php artisan db:seed --class=DashboardDemoSeeder`
 *
 * Seeds rich rows for SD-13 (the unit the seeded admin_unit account
 * admin.sd@yapinet.id sits on) and SMP-12, so every number a dashboard
 * renders has real rows behind it: KPI, per-unit recap, alerts, and the
 * unit-scoped Ringkasan Akademik watchlist (siswa perlu perhatian / absensi
 * tinggi / penurunan nilai / nilai di bawah KKM).
 *
 * Deliberately idempotent for a dev database: students/classes/subjects are
 * keyed on their natural unique columns; schedules, attendance sessions and
 * their records are rebuilt from the demo classrooms each run; grades and
 * bills use updateOrCreate on their unique keys.
 */
class DashboardDemoSeeder extends Seeder
{
    /** School-wide subject catalogue reused by every room. */
    private const SUBJECTS = ['PAI', 'Matematika', 'Bahasa Indonesia', 'Bahasa Inggris', 'IPA'];

    public function run(): void
    {
        $sdUnit = SchoolUnit::findByCode('SD-13');
        $smpUnit = SchoolUnit::findByCode('SMP-12');

        if (! $sdUnit || ! $smpUnit) {
            return;
        }

        $year = AcademicYear::where('year', '2026/2027')->first()
            ?? AcademicYear::firstOrCreate(['year' => '2026/2027'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        $term = Term::updateOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'ganjil'],
            ['starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true]
        );
        Term::where('academic_year_id', $year->id)->where('name', '!=', 'ganjil')->update(['is_active' => false]);

        // Previous year + matching term, so the "penurunan nilai" report can
        // compare the current term against a real prior one.
        $prevYear = AcademicYear::firstOrCreate(
            ['year' => '2025/2026'],
            ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']
        );
        $prevTerm = Term::firstOrCreate(
            ['academic_year_id' => $prevYear->id, 'name' => 'ganjil'],
            ['starts_on' => '2025-07-01', 'ends_on' => '2025-12-31', 'is_active' => false]
        );

        $this->seedSchoolWideCatalogue();

        // Convenience admin_unit for SMP, mirroring admin.sd@yapinet.id.
        User::updateOrCreate(
            ['email' => 'admin.smp@yapinet.id'],
            [
                'name' => 'Admin SMPI Al Azhar 12',
                'role' => 'admin_unit',
                'school_unit_id' => $smpUnit->id,
                'is_active' => true,
                'activated_at' => now(),
                'email_verified_at' => now(),
            ]
        );

        $this->seedUnit($sdUnit, $year, $term, $prevYear, $prevTerm);
        $this->seedUnit($smpUnit, $year, $term, $prevYear, $prevTerm);
    }

    private function seedSchoolWideCatalogue(): void
    {
        foreach (self::SUBJECTS as $code) {
            Subject::firstOrCreate(
                ['school_unit_id' => null, 'code' => $code],
                ['name' => $code, 'is_active' => true]
            );
        }

        $rules = [
            ['code' => 'terlambat', 'name' => 'Terlambat masuk kelas', 'type' => 'violation', 'category' => 'Kedisiplinan', 'points' => 10],
            ['code' => 'alpa', 'name' => 'Alpa tanpa keterangan', 'type' => 'violation', 'category' => 'Kedisiplinan', 'points' => 15],
            ['code' => 'seragam', 'name' => 'Seragam tidak sesuai aturan', 'type' => 'violation', 'category' => 'Kedisplinan', 'points' => 5],
            ['code' => 'juara-lomba', 'name' => 'Juara lomba', 'type' => 'merit', 'category' => 'Prestasi', 'points' => 15],
            ['code' => 'hafalan', 'name' => 'Capaian hafalan', 'type' => 'merit', 'category' => 'Keagamaan', 'points' => 10],
            ['code' => 'inisiatif', 'name' => 'Inisiatif membantu', 'type' => 'merit', 'category' => 'Kepribadian', 'points' => 5],
        ];

        foreach ($rules as $rule) {
            PointRule::updateOrCreate(['school_unit_id' => null, 'code' => $rule['code']], $rule + ['is_active' => true, 'sort_order' => 0]);
        }
    }

    private function seedUnit(
        SchoolUnit $unit,
        AcademicYear $year,
        Term $term,
        AcademicYear $prevYear,
        Term $prevTerm,
    ): void {
        $tingkatList = $unit->jenjang_group === 'smp' ? [7, 8, 9] : [1, 2, 3, 4, 5, 6];

        // ---- Teachers -------------------------------------------------------
        $teachers = collect(range(1, 8))->map(function (int $i) use ($unit) {
            $slug = Str::slug($unit->code);

            return User::updateOrCreate(
                ['email' => "guru.{$slug}.{$i}@yapinet.id"],
                [
                    'name' => "Guru {$unit->label} {$i}",
                    'role' => 'guru',
                    'school_unit_id' => $unit->id,
                    'is_active' => true,
                    'activated_at' => now(),
                    'email_verified_at' => now(),
                ]
            );
        });

        // ---- Classrooms (current + previous year, so prev-term grades point
        // at a real room of their own year). --------------------------------
        $curClassrooms = $this->classroomsFor($unit, $year, $tingkatList, $teachers);
        $prevClassrooms = $this->classroomsFor($unit, $prevYear, $tingkatList, $teachers);

        // ---- Students + enrollments ----------------------------------------
        $students = collect();
        $seq = 1;
        $unitTag = $unit->jenjang_group === 'smp' ? '02' : '01';

        foreach ($tingkatList as $tingkat) {
            $curRoom = $curClassrooms->firstWhere('name', $this->roomName($tingkat));

            for ($k = 1; $k <= 5; $k++) {
                $nis = sprintf('2026%s%02d', $unitTag, $seq);
                $student = Student::updateOrCreate(
                    ['nis' => $nis],
                    [
                        'school_unit_id' => $unit->id,
                        'entry_year_id' => $year->id,
                        'nama_lengkap' => $this->studentName($seq, $k),
                        'nama_panggilan' => '',
                        'jenis_kelamin' => $seq % 2 ? 'L' : 'P',
                        'status' => 'active',
                    ]
                );

                Enrollment::updateOrCreate(
                    ['student_id' => $student->id, 'academic_year_id' => $year->id],
                    [
                        'classroom_id' => $curRoom->id,
                        'status' => 'active',
                        'joined_on' => $year->starts_on,
                        // A deliberate rollup for ~1 in 5 students makes the
                        // "absensi tinggi" (alpa >= 5) watchlist light up.
                        'absent_count' => $seq % 5 === 0 ? 5 + ($seq % 4) : $seq % 4,
                        'sick_count' => $seq % 6,
                        'permit_count' => $seq % 7,
                    ]
                );

                Enrollment::updateOrCreate(
                    ['student_id' => $student->id, 'academic_year_id' => $prevYear->id],
                    [
                        'classroom_id' => $prevClassrooms->firstWhere('name', $this->roomName($tingkat))->id,
                        'status' => 'active',
                        'joined_on' => $prevYear->starts_on,
                    ]
                );

                $students->push($student);
                $seq++;
            }
        }

        // A couple of active students with no current-year rombel: feeds the
        // "Siswa aktif belum ditempatkan di kelas" alert + attention count.
        foreach ([1, 2] as $i) {
            $nis = sprintf('2026%s%02d', $unitTag, $seq);
            Student::updateOrCreate(
                ['nis' => $nis],
                [
                    'school_unit_id' => $unit->id,
                    'entry_year_id' => $year->id,
                    'nama_lengkap' => $this->studentName($seq, $i),
                    'nama_panggilan' => '',
                    'jenis_kelamin' => 'L',
                    'status' => 'active',
                ]
            );
            $seq++;
        }

        // ---- Schedules (needed by attendance sessions) ----------------------
        $this->rebuildSchedules($curClassrooms, $term, $teachers);

        // ---- Grades (current + previous term) -------------------------------
        $subjects = Subject::whereNull('school_unit_id')->get();

        foreach ($students as $student) {
            $curEnr = $student->enrollments()->where('academic_year_id', $year->id)->first();
            $prevEnr = $student->enrollments()->where('academic_year_id', $prevYear->id)->first();
            if (! $curEnr || ! $prevEnr) {
                continue;
            }

            $this->seedGrades($student, $curEnr->classroom_id, $prevEnr->classroom_id, $subjects, $term, $prevTerm, $teachers->first());
        }

        // ---- Attendance (incl. today) ----------------------------------------
        $this->seedAttendance($curClassrooms, $students, $term, $teachers->first());

        // ---- Points ----------------------------------------------------------
        $this->seedPoints($students, $term, $teachers->first());

        // ---- Achievements ----------------------------------------------------
        $this->seedAchievements($unit, $students, $teachers);

        // ---- Extracurriculars -----------------------------------------------
        $this->seedExtracurriculars($unit, $year, $students, $teachers->first());

        // ---- SPP bills (paid / partial / unpaid / overdue) -------------------
        $this->seedBills($unit, $year, $term, $students);
    }

    /** @return Collection<int, Classroom> keyed by room name */
    private function classroomsFor(SchoolUnit $unit, AcademicYear $year, array $tingkatList, Collection $teachers): Collection
    {
        return collect($tingkatList)->mapWithKeys(function (int $tingkat, int $i) use ($unit, $year, $teachers) {
            $name = $this->roomName($tingkat);
            $room = Classroom::firstOrCreate(
                ['academic_year_id' => $year->id, 'school_unit_id' => $unit->id, 'name' => $name],
                [
                    'tingkat' => $tingkat,
                    'homeroom_teacher_id' => $teachers->get($i % $teachers->count())?->id,
                    'capacity' => 20,
                    'is_active' => true,
                ]
            );

            return [$name => $room];
        });
    }

    private function roomName(int $tingkat): string
    {
        return sprintf('%d-%s', $tingkat, 'A');
    }

    private function studentName(int $seq, int $k): string
    {
        $male = ['Muhammad', 'Ahmad', 'Hafizh', 'Rizky', 'Faris', 'Ilham', 'Zaky', 'Raka'];
        $female = ['Aisyah', 'Nafisa', 'Zahra', 'Keysha', 'Syafa', 'Khansa', 'Aulia', 'Salma'];
        $last = ['Pratama', 'Ramadhan', 'Hidayat', 'Saputra', 'Mulyadi', 'Hakim', 'Putra', 'Maulana'];

        $gender = $seq % 2 ? 'L' : 'P';
        $first = $gender === 'L' ? $male[$k % count($male)] : $female[$k % count($female)];
        $family = $last[$seq % count($last)];

        return $first.' '.$family;
    }

    private function rebuildSchedules(Collection $classrooms, Term $term, Collection $teachers): void
    {
        $classroomIds = $classrooms->pluck('id');
        $scheduleIds = ClassSchedule::whereIn('classroom_id', $classroomIds)->pluck('id');
        AttendanceSession::whereIn('class_schedule_id', $scheduleIds)->delete();
        ClassSchedule::whereIn('id', $scheduleIds)->delete();

        $subjects = Subject::whereNull('school_unit_id')->get();

        $i = 0;
        foreach ($classrooms as $room) {
            foreach ($subjects as $subject) {
                ClassSchedule::create([
                    'classroom_id' => $room->id,
                    'subject_id' => $subject->id,
                    'teacher_id' => $teachers->get($i % $teachers->count())?->id,
                    'day_of_week' => ($i % 6) + 1,
                    'start_time' => sprintf('%02d:%02d:00', 7 + ($i % 8), ($i * 5) % 60),
                    'end_time' => sprintf('%02d:%02d:00', 8 + ($i % 8), ($i * 5) % 60),
                ]);
                $i++;
            }
        }
    }

    private function seedGrades(
        Student $student,
        int $curClassroomId,
        int $prevClassroomId,
        Collection $subjects,
        Term $curTerm,
        Term $prevTerm,
        ?User $teacher,
    ): void {
        mt_srand(($student->id * 31) + 17);

        $curBase = mt_rand(60, 92);
        if (mt_rand(1, 5) === 1) {
            $curBase = mt_rand(46, 66); // below-KKM cohort
        }

        $decline = mt_rand(1, 5) === 1;
        $prevBase = $decline ? $curBase + mt_rand(6, 13) : max(45, $curBase + mt_rand(-3, 5));

        foreach ($subjects as $subject) {
            foreach (['tugas', 'uts', 'uas'] as $category) {
                Grade::updateOrCreate(
                    ['student_id' => $student->id, 'subject_id' => $subject->id, 'term_id' => $curTerm->id, 'category' => $category],
                    [
                        'classroom_id' => $curClassroomId,
                        'score' => min(100, max(40, $curBase + mt_rand(-3, 3))),
                        'recorded_by' => $teacher?->id,
                    ]
                );

                Grade::updateOrCreate(
                    ['student_id' => $student->id, 'subject_id' => $subject->id, 'term_id' => $prevTerm->id, 'category' => $category],
                    [
                        'classroom_id' => $prevClassroomId,
                        'score' => min(100, max(40, $prevBase + mt_rand(-3, 3))),
                        'recorded_by' => $teacher?->id,
                    ]
                );
            }
        }
    }

    private function seedAttendance(Collection $classrooms, Collection $students, Term $term, ?User $teacher): void
    {
        $enrollments = Enrollment::whereIn('student_id', $students->pluck('id'))->where('academic_year_id', $term->academic_year_id)->get()->keyBy('student_id');

        $dates = collect(range(0, 5))->map(fn (int $day) => now()->subDays($day)->toDateString());

        foreach ($classrooms as $room) {
            $roomStudents = $enrollments->where('classroom_id', $room->id);

            $schedule = ClassSchedule::where('classroom_id', $room->id)->first();
            if (! $schedule) {
                continue;
            }

            foreach ($dates as $date) {
                $isToday = $date === now()->toDateString();
                $session = AttendanceSession::create([
                    'class_schedule_id' => $schedule->id,
                    'occurred_on' => $date,
                    'token' => 'demo-'.Str::random(40),
                    'status' => $isToday ? 'open' : 'closed',
                    'opened_by' => $teacher?->id,
                    'opened_at' => Carbon::parse($date.' 07:00:00'),
                    'expires_at' => Carbon::parse($date.' 15:00:00'),
                ]);

                foreach ($roomStudents as $enrollment) {
                    $key = ($enrollment->student_id + $date[8] * 17) % 25;
                    if ($key === 0) {
                        $status = 'alpa';
                    } elseif ($key % 9 === 0) {
                        $status = 'sakit';
                    } elseif ($key % 7 === 0) {
                        $status = 'izin';
                    } else {
                        $status = 'hadir';
                    }

                    AttendanceRecord::create([
                        'student_id' => $enrollment->student_id,
                        'attendance_session_id' => $session->id,
                        'classroom_id' => $room->id,
                        'term_id' => $term->id,
                        'attendance_status' => $status,
                        'occurred_on' => $date,
                        'source' => 'guru',
                        'recorded_by' => $teacher?->id,
                        'record_status' => 'recorded',
                    ]);
                }
            }
        }
    }

    private function seedPoints(Collection $students, Term $term, ?User $teacher): void
    {
        $rules = PointRule::whereNull('school_unit_id')->get()->keyBy('code');

        foreach ($students as $i => $student) {
            if ($student->id % 6 === 0) {
                $rule = $i % 2 ? $rules->get('alpa') : $rules->get('seragam');
                PointRecord::firstOrCreate(
                    ['student_id' => $student->id, 'term_id' => $term->id, 'description' => $rule->name],
                    [
                        'point_rule_id' => $rule->id,
                        'type' => 'violation',
                        'points' => -$rule->points,
                        'occurred_on' => $term->starts_on->addDays($student->id % 60),
                        'recorded_by' => $teacher?->id,
                        'status' => 'recorded',
                    ]
                );
            }

            if ($student->id % 4 === 0) {
                $rule = $i % 2 ? $rules->get('hafalan') : $rules->get('inisiatif');
                PointRecord::firstOrCreate(
                    ['student_id' => $student->id, 'term_id' => $term->id, 'description' => $rule->name],
                    [
                        'point_rule_id' => $rule->id,
                        'type' => 'merit',
                        'points' => $rule->points,
                        'occurred_on' => $term->starts_on->addDays(($student->id * 3) % 80),
                        'recorded_by' => $teacher?->id,
                        'status' => 'recorded',
                    ]
                );
            }
        }
    }

    private function seedAchievements(SchoolUnit $unit, Collection $students, Collection $teachers): void
    {
        $studentRows = $students->take(4);
        $list = [
            'Juara Olimpiade Matematika',
            'Juara Tahfidz 1 Juz',
            'Cerdas Cermat PAI',
            'Lomba Mewarnai Tingkat Kecamatan',
        ];

        $i = 0;
        foreach ($studentRows as $student) {
            $name = $list[$i % count($list)];
            Achievement::updateOrCreate(
                ['student_id' => $student->id, 'nama_prestasi' => $name, 'tanggal_event' => '2026-08-20'],
                [
                    'achiever_type' => 'siswa',
                    'school_unit_id' => $unit->id,
                    'kategori' => 'Akademik',
                    'tingkat' => 'Kabupaten/Kota',
                    'juara' => '1',
                    'nama_event' => $name,
                    'penyelenggara' => 'Dinas Pendidikan',
                    'status' => $i % 3 === 2 ? 'pending' : 'verified',
                    'point_awarded' => 15,
                    'verified_by' => $teachers->first()?->id,
                    'verified_at' => $i % 3 === 2 ? null : now(),
                ]
            );
            $i++;
        }

        $teacher = $teachers->first();
        Achievement::updateOrCreate(
            ['teacher_user_id' => $teacher?->id, 'nama_prestasi' => 'Pembina Pramuka Terbaik', 'tanggal_event' => '2026-09-01'],
            [
                'achiever_type' => 'guru',
                'school_unit_id' => $unit->id,
                'kategori' => 'Non-Akademik',
                'tingkat' => 'Provinsi',
                'juara' => '2',
                'nama_event' => 'Jambore Pramuka',
                'penyelenggara' => 'Kwarda',
                'status' => 'verified',
                'point_awarded' => 10,
                'verified_at' => now(),
            ]
        );
    }

    private function seedExtracurriculars(SchoolUnit $unit, AcademicYear $year, Collection $students, ?User $pembina): void
    {
        $activities = $unit->jenjang_group === 'smp'
            ? ['Pramuka', 'Robotik', 'Badminton']
            : ['Pramuka', 'Tahfidz', 'Futsal'];

        foreach ($activities as $name) {
            $ekskul = Extracurricular::firstOrCreate(
                ['school_unit_id' => $unit->id, 'academic_year_id' => $year->id, 'name' => $name],
                ['description' => "Kegiatan {$name}", 'pembina_id' => $pembina?->id, 'capacity' => 30, 'is_active' => true]
            );

            foreach ($students->take(5) as $student) {
                ExtracurricularMember::firstOrCreate(
                    ['extracurricular_id' => $ekskul->id, 'student_id' => $student->id, 'academic_year_id' => $year->id],
                    ['status' => 'active', 'joined_on' => $year->starts_on, 'assigned_at' => now()]
                );
            }
        }
    }

    private function seedBills(SchoolUnit $unit, AcademicYear $year, Term $term, Collection $students): void
    {
        $spp = FeeType::where('code', 'spp')->first();
        if (! $spp) {
            return;
        }

        $rate = FeeRate::where('fee_type_id', $spp->id)
            ->where('school_unit_id', $unit->id)
            ->where('academic_year_id', $year->id)
            ->whereNull('tingkat')
            ->first();
        $amount = (float) ($rate?->amount ?? ($unit->jenjang_group === 'smp' ? 750000 : 650000));

        foreach ($students as $student) {
            $mk = $student->id % 4; // deterministic payment pattern

            foreach ([7, 8, 9] as $month) {
                $period = sprintf('%04d/%02d', $year->starts_on->year, $month);
                $due = match (true) {
                    $month === 9 && $mk === 2 => now()->subDays(5),                     // overdue, due in the past
                    $month === 9 => now()->addDays(10),
                    default => Carbon::createFromFormat('Y/m', $period)->day(10),
                };

                $status = match (true) {
                    $month === 7 => 'paid',
                    $month === 8 && $mk === 0 => 'partial',
                    $month === 8 => 'paid',
                    $month === 9 && $mk === 0 => 'partial',
                    $month === 9 && $mk >= 1 => 'unpaid',
                    default => 'paid',
                };

                $paid = match ($status) {
                    'paid' => $amount,
                    'partial' => round($amount / 2, 2),
                    default => 0,
                };

                $monthName = Carbon::createFromFormat('Y/m', $period)->translatedFormat('F').' '.$year->starts_on->year;

                $dedupKey = "spp:{$student->id}:{$year->id}:{$month}";
                $payload = [
                    'fee_type_id' => $spp->id,
                    'fee_rate_id' => $rate?->id,
                    'academic_year_id' => $year->id,
                    'term_id' => $term->id,
                    'period_month' => $month,
                    'description' => "SPP {$monthName} - {$student->nama_lengkap}",

                    'subtotal' => $amount,
                    'discount_amount' => 0,
                    'late_fee' => 0,
                    'total_amount' => $amount,
                    'paid_amount' => $paid,
                    'remaining_amount' => $amount - $paid,
                    'status' => $status,
                    'due_date' => $due->toDateString(),
                    'allow_installment' => true,
                    'issued_at' => $due->copy()->startOfMonth(),
                ];

                $existing = Bill::where('student_id', $student->id)->where('dedup_key', $dedupKey)->first();

                if ($existing) {
                    $existing->update($payload);
                } else {
                    Bill::create($payload + [
                        'student_id' => $student->id,
                        'dedup_key' => $dedupKey,
                        'bill_number' => Bill::generateNumber($spp, $year->year, $month),
                    ]);
                }
            }
        }
    }
}
