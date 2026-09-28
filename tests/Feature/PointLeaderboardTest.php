<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\PointRecord;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leaderboard poin (feature batch Poin 6): two top-5 boards off the same
 * signed ledger - positive rows count as merit, negative as violation,
 * revoked rows excluded by active(), and one shared unit+jenjang filter
 * pair drives both. "Terverifikasi" is the ledger's own contract: only
 * verified decisions ever write point_records.
 */
class PointLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    private Term $term;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);
        $this->term = Term::create(['academic_year_id' => $year->id, 'name' => 'ganjil', 'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31']);
        $this->term->activate();

        $this->admin = User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin'.uniqid().'@yapinet.id',
            'role' => 'admin',
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    private function award(Student $student, int $points, bool $revoked = false): void
    {
        PointRecord::create([
            'student_id' => $student->id,
            'term_id' => $this->term->id,
            'type' => $points > 0 ? 'merit' : 'violation',
            'points' => $points,
            'occurred_on' => '2026-09-01',
            'description' => 'uji leaderboard',
            'recorded_by' => $this->admin->id,
            'status' => $revoked ? 'revoked' : 'recorded',
            'revoke_reason' => $revoked ? 'uji' : null,
        ]);
    }

    private function studentIn(string $unitCode, string $group, ?string $classroom, int $tingkat, string $nama): Student
    {
        $unit = SchoolUnit::firstOrCreate(['code' => $unitCode], ['label' => $unitCode, 'jenjang_group' => $group]);

        $student = Student::create([
            'nama_lengkap' => $nama,
            'jenis_kelamin' => 'L',
            'school_unit_id' => $unit->id,
            'entry_year_id' => $this->term->academic_year_id,
            'status' => 'active',
        ]);

        if ($classroom !== null) {
            $classroom = Classroom::firstOrCreate(
                ['school_unit_id' => $unit->id, 'academic_year_id' => $this->term->academic_year_id, 'name' => $classroom],
                ['tingkat' => $tingkat, 'is_active' => true],
            );

            Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $this->term->academic_year_id,
                'classroom_id' => $classroom->id,
                'status' => 'active',
                'joined_on' => '2026-07-01',
            ]);
        }

        return $student;
    }

    public function test_one_student_with_both_signs_lands_on_both_boards(): void
    {
        $anak = $this->studentIn('SD-13', 'sd', '1-A', 1, 'Anak Dua Sisi');
        $this->award($anak, 20);
        $this->award($anak, -30);

        $body = $this->actingAs($this->admin)->getJson('/api/admin/points/leaderboard')->assertOk();

        $this->assertSame(20, $body->json('merit.0.total'));
        $this->assertSame('Anak Dua Sisi', $body->json('merit.0.student.nama_lengkap'));
        $this->assertSame(30, $body->json('violation.0.total'));
        $this->assertSame('Anak Dua Sisi', $body->json('violation.0.student.nama_lengkap'));
    }

    public function test_revoked_records_and_unit_jenjang_filters_shape_the_boards(): void
    {
        $sdSatu = $this->studentIn('SD-13', 'sd', '1-A', 1, 'Anak SD Satu');
        $sdTiga = $this->studentIn('SD-13', 'sd', '3-A', 3, 'Anak SD Tiga');
        $smpTujuh = $this->studentIn('SMP-12', 'smp', '7-A', 7, 'Anak SMP Tujuh');

        $this->award($sdSatu, 50);
        $this->award($sdTiga, 40);
        $this->award($smpTujuh, 60);
        // A revoked merit must not count.
        $this->award($sdSatu, 999, revoked: true);

        $all = $this->actingAs($this->admin)->getJson('/api/admin/points/leaderboard')->assertOk();
        $this->assertSame(
            ['Anak SMP Tujuh', 'Anak SD Satu', 'Anak SD Tiga'],
            collect($all->json('merit'))->pluck('student.nama_lengkap')->all(),
            'urut nilai tertinggi, revoked tak dihitung, max 5',
        );

        $byUnit = $this->actingAs($this->admin)->getJson('/api/admin/points/leaderboard?unit=SD-13')->assertOk();
        $this->assertSame(['Anak SD Satu', 'Anak SD Tiga'], collect($byUnit->json('merit'))->pluck('student.nama_lengkap')->all());

        $byJenjang = $this->actingAs($this->admin)->getJson('/api/admin/points/leaderboard?jenjang=sd-3')->assertOk();
        $this->assertSame(['Anak SD Tiga'], collect($byJenjang->json('merit'))->pluck('student.nama_lengkap')->all());

        // RBAC: a per-unit admin never sees another unit's students on
        // either board - a foreign ?unit= intersects with their own scope
        // to nothing (the audit-T18 contract).
        $adminUnit = User::create([
            'name' => 'Admin Unit SMP',
            'email' => 'ausmp'.uniqid().'@yapinet.id',
            'role' => 'admin_unit',
            'school_unit_id' => SchoolUnit::where('code', 'SMP-12')->first()->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $scoped = $this->actingAs($adminUnit)->getJson('/api/admin/points/leaderboard')->assertOk();
        $this->assertSame(['Anak SMP Tujuh'], collect($scoped->json('merit'))->pluck('student.nama_lengkap')->all());

        $foreign = $this->actingAs($adminUnit)->getJson('/api/admin/points/leaderboard?unit=SD-13')->assertOk();
        $this->assertSame([], collect($foreign->json('merit'))->pluck('student.nama_lengkap')->all());
    }

    public function test_no_active_term_means_empty_boards(): void
    {
        $this->term->update(['is_active' => false]);

        $body = $this->actingAs($this->admin)->getJson('/api/admin/points/leaderboard')->assertOk();
        $this->assertNull($body->json('term'));
        $this->assertSame([], $body->json('merit'));
        $this->assertSame([], $body->json('violation'));
    }

    public function test_no_active_term_returns_the_full_paged_shape(): void
    {
        // Between semesters the response must keep the {summary, students:
        // {data, meta}} shape (audit 2026-09-28: the flat empty array used
        // to crash the frontend's summary fallback into a toast + skeleton).
        $this->term->update(['is_active' => false]);

        $body = $this->actingAs($this->admin)->getJson('/api/admin/points')->assertOk();

        $this->assertNull($body->json('term'));
        $this->assertSame(0, $body->json('summary.total'));
        $this->assertSame([], $body->json('students.data'));
        $this->assertSame(1, $body->json('students.meta.last_page'));
    }

    public function test_a_coarse_jenjang_filter_keeps_unplaced_students(): void
    {
        // A child between rombels has no rung to sit on, but the discipline
        // roster must not lose them when the operator picks a COARSE
        // jenjang - exactly the student the sweep watches (audit
        // 2026-09-28). Granular keys still resolve through the enrollment.
        $placed = $this->studentIn('SD-13', 'sd', '1-A', 1, 'Anak Terpasang');
        $this->award($placed, -30);
        $unplaced = Student::create([
            'nama_lengkap' => 'Anak Tanpa Rombel', 'jenis_kelamin' => 'L',
            'school_unit_id' => SchoolUnit::where('code', 'SD-13')->value('id'),
            'entry_year_id' => $this->term->academic_year_id, 'status' => 'active',
        ]);
        $this->award($unplaced, -60);

        $coarse = $this->actingAs($this->admin)->getJson('/api/admin/points?jenjang=sd')->assertOk();
        $this->assertSame(2, $coarse->json('summary.total'), 'coarse sd mencakup yang belum ditempatkan');

        $fine = $this->actingAs($this->admin)->getJson('/api/admin/points?jenjang=sd-1')->assertOk();
        $this->assertSame(1, $fine->json('summary.total'), 'granular sd-1 hanya yang punya rombel');
    }

    public function test_the_student_balance_list_is_paginated_and_filterable(): void
    {
        // Bug batch Poin 5: the manage-points roster is a server-side
        // paged list (20/page, worst balance first) whose unit/jenjang/
        // search filters ride the same query - and whose summary keeps
        // counting the WHOLE filtered scope, not just the visible page.
        foreach (range(1, 25) as $i) {
            $student = $this->studentIn('SD-13', 'sd', '1-A', 1, "Anak Uji {$i}");
            $this->award($student, -$i);
        }
        $outsider = $this->studentIn('SMP-12', 'smp', '7-B', 7, 'Anak Luuar Cakupan');
        $this->award($outsider, 5);

        $page1 = $this->actingAs($this->admin)->getJson('/api/admin/points')->assertOk();
        $this->assertSame(26, $page1->json('summary.total'));
        $this->assertSame(20, count($page1->json('students.data')));
        $this->assertSame(1, $page1->json('students.meta.current_page'));
        $this->assertSame(2, $page1->json('students.meta.last_page'));
        // Worst balance first: -25 opens page 1.
        $this->assertSame(-25, $page1->json('students.data.0.balance'));

        $page2 = $this->actingAs($this->admin)->getJson('/api/admin/points?page=2')->assertOk();
        $this->assertSame(6, count($page2->json('students.data')));
        $this->assertSame(-5, $page2->json('students.data.0.balance'));

        $byUnit = $this->actingAs($this->admin)->getJson('/api/admin/points?unit=SMP-12')->assertOk();
        $this->assertSame(1, $byUnit->json('summary.total'));
        $this->assertSame(['Anak Luuar Cakupan'], collect($byUnit->json('students.data'))->pluck('student.nama_lengkap')->all());

        $byJenjang = $this->actingAs($this->admin)->getJson('/api/admin/points?jenjang=sd-1')->assertOk();
        $this->assertSame(25, $byJenjang->json('summary.total'));

        $bySearch = $this->actingAs($this->admin)->getJson('/api/admin/points?search=Uji 3')->assertOk();
        $this->assertSame(['Anak Uji 3'], collect($bySearch->json('students.data'))->pluck('student.nama_lengkap')->all());

        // The ambang toggle with no threshold bands configured narrows to
        // nothing - every row's threshold is null.
        $flagged = $this->actingAs($this->admin)->getJson('/api/admin/points?flagged=1')->assertOk();
        $this->assertSame([], $flagged->json('students.data'));
        $this->assertSame(26, $flagged->json('summary.total'), 'summary counts the scope, not the toggle');
    }
}
