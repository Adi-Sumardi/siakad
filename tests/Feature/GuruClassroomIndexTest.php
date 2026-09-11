<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSchedule;
use App\Models\Classroom;
use App\Models\SchoolUnit;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The guru dashboard's classroom list - in particular its schedules_today
 * summary, which the frontend splits into "classes with lessons today" on
 * top and the full unit roster below. Two things must hold: only the
 * requesting day's periods count, and "today" is decided by the Jakarta
 * clock (config timezone is UTC, which is a day behind every morning
 * 00:00-07:00 WIB - exactly when a teacher opens this screen).
 */
class GuruClassroomIndexTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;
    private SchoolUnit $smp;
    private Classroom $kelas1a;
    private Classroom $kelas2b;
    private User $guru;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);

        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        $this->kelas1a = Classroom::create(['school_unit_id' => $this->sd->id, 'academic_year_id' => $year->id, 'tingkat' => 1, 'name' => '1A']);
        $this->kelas2b = Classroom::create(['school_unit_id' => $this->sd->id, 'academic_year_id' => $year->id, 'tingkat' => 2, 'name' => '2B']);

        $this->guru = User::create([
            'name' => 'Guru SD', 'email' => 'guru'.uniqid().'@yapinet.id', 'role' => 'guru',
            'school_unit_id' => $this->sd->id, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // pinned per test, never leak into the next one
        parent::tearDown();
    }

    private function subject(): Subject
    {
        return Subject::create(['school_unit_id' => $this->sd->id, 'code' => 'MTK'.uniqid(), 'name' => 'Matematika']);
    }

    public function test_schedules_today_summarizes_each_classroom(): void
    {
        // 2026-09-11 is a Friday (day_of_week 5), mid-morning Jakarta time.
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00', 'Asia/Jakarta'));

        $guruLain = User::create([
            'name' => 'Guru Lain', 'email' => 'lain'.uniqid().'@yapinet.id', 'role' => 'guru',
            'school_unit_id' => $this->sd->id, 'is_active' => true, 'activated_at' => now(),
        ]);

        // The requesting teacher's own first period, a colleague's second
        // period, and a Monday period that must not count on a Friday.
        ClassSchedule::create(['classroom_id' => $this->kelas1a->id, 'subject_id' => $this->subject()->id, 'teacher_id' => $this->guru->id, 'day_of_week' => 5, 'start_time' => '07:00', 'end_time' => '08:00']);
        ClassSchedule::create(['classroom_id' => $this->kelas1a->id, 'subject_id' => $this->subject()->id, 'teacher_id' => $guruLain->id, 'day_of_week' => 5, 'start_time' => '09:00', 'end_time' => '10:00']);
        ClassSchedule::create(['classroom_id' => $this->kelas1a->id, 'subject_id' => $this->subject()->id, 'teacher_id' => $this->guru->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00']);

        $res = $this->actingAs($this->guru)->getJson('/api/guru/classrooms')->assertOk();

        $kelas1a = $res->json('classrooms.0');
        $this->assertSame('1A', $kelas1a['name']);
        $this->assertSame(2, $kelas1a['schedules_today']['total']);
        $this->assertSame(1, $kelas1a['schedules_today']['mine']); // colleague's period is the class's, not mine
        $this->assertStringStartsWith('07:00', $kelas1a['schedules_today']['first_start']);
        $this->assertStringStartsWith('10:00', $kelas1a['schedules_today']['last_end']);

        $kelas2b = $res->json('classrooms.1');
        $this->assertSame('2B', $kelas2b['name']);
        $this->assertSame(0, $kelas2b['schedules_today']['total']);
        $this->assertSame(0, $kelas2b['schedules_today']['mine']);
        $this->assertNull($kelas2b['schedules_today']['first_start']);
        $this->assertNull($kelas2b['schedules_today']['last_end']);
    }

    public function test_today_is_decided_by_the_jakarta_clock(): void
    {
        // 00:30 WIB Friday is still Thursday in UTC (the app's config
        // timezone) - a bare Carbon::now() would summarize Thursday instead.
        Carbon::setTestNow(Carbon::parse('2026-09-11 00:30:00', 'Asia/Jakarta'));

        ClassSchedule::create(['classroom_id' => $this->kelas1a->id, 'subject_id' => $this->subject()->id, 'teacher_id' => $this->guru->id, 'day_of_week' => 5, 'start_time' => '07:00', 'end_time' => '08:00']);
        ClassSchedule::create(['classroom_id' => $this->kelas1a->id, 'subject_id' => $this->subject()->id, 'teacher_id' => $this->guru->id, 'day_of_week' => 4, 'start_time' => '07:00', 'end_time' => '08:00']);

        $res = $this->actingAs($this->guru)->getJson('/api/guru/classrooms')->assertOk();

        // Friday's period counts; Thursday's (UTC's "today") does not.
        $this->assertSame(1, $res->json('classrooms.0.schedules_today.total'));
    }

    public function test_another_units_classroom_is_not_listed(): void
    {
        Classroom::create([
            'school_unit_id' => $this->smp->id, 'academic_year_id' => AcademicYear::first()->id,
            'tingkat' => 7, 'name' => '7A',
        ]);

        $res = $this->actingAs($this->guru)->getJson('/api/guru/classrooms')->assertOk();

        $this->assertSame(['1A', '2B'], array_column($res->json('classrooms'), 'name'));
    }
}
