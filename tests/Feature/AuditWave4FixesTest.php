<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillingApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gelombang 4 dari audit fungsional 2026-09-21: the cleanup batch. The
 * announcement scope fails closed without a user, the VA poller drains the
 * WHOLE pending queue instead of the freshest 50, guardian emails are
 * encrypted like the phone numbers beside them, a unit move is refused while
 * an enrollment is live (cross-unit is promotion's job), and the dead chart
 * endpoints are gone.
 */
class AuditWave4FixesTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private AcademicYear $year;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);

        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();

        $this->admin = User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin'.uniqid().'@yapinet.id',
            'role' => 'admin',
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    public function test_the_announcement_scope_fails_closed_without_a_user(): void
    {
        Announcement::create(['title' => 'Rapat Guru', 'body' => 'Besok subuh.']);

        // Null is a bug or a future caller we haven't thought through - the
        // old branch handed it EVERY notice instead of none.
        $this->assertSame(
            0,
            Announcement::query()->visibleTo(null)->count(),
            'tanpa user, scope harus menutup (0 baris), bukan membuka semua'
        );
    }

    public function test_the_poller_drains_the_whole_queue_not_just_the_freshest_fifty(): void
    {
        $settled = [];

        $this->app->bind(BillingApiClient::class, function () use (&$settled) {
            return new class($settled) extends BillingApiClient
            {
                public function __construct(private array &$settled)
                {
                    // The parent constructor stays deliberately unruncated -
                    // the poller only calls getByVaNumber().
                }

                public function getByVaNumber(string $vaNumber): array
                {
                    $this->settled[] = $vaNumber;

                    return ['sisa' => 0]; // the bank says: fully paid
                }
            };
        });

        for ($i = 0; $i < 60; $i++) {
            Payment::create([
                'payment_number' => 'PAY-POLL-'.$i,
                'amount' => 100000,
                'method' => 'virtual_account',
                'status' => 'processing',
                'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => 'VA-'.$i],
            ]);
        }

        $this->artisan('payments:poll-billing-va');

        // The old "latest 50" starved the back of the queue forever; with 60
        // pending and no --limit, every one of them gets its turn.
        $this->assertSame(60, Payment::where('status', 'completed')->count());
        $this->assertCount(60, array_unique($settled));
    }

    public function test_the_poller_still_honours_an_explicit_limit(): void
    {
        $this->app->bind(BillingApiClient::class, fn () => new class extends BillingApiClient
        {
            public function __construct() {}

            public function getByVaNumber(string $vaNumber): array
            {
                return ['sisa' => 0];
            }
        });

        for ($i = 0; $i < 10; $i++) {
            Payment::create([
                'payment_number' => 'PAY-CAP-'.$i,
                'amount' => 100000,
                'method' => 'virtual_account',
                'status' => 'processing',
                'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => 'VA-CAP-'.$i],
            ]);
        }

        $this->artisan('payments:poll-billing-va', ['--limit' => 3]);

        $this->assertSame(3, Payment::where('status', 'completed')->count());
    }

    public function test_guardian_emails_are_encrypted_at_rest_and_findable_by_hash(): void
    {
        $guardian = Guardian::create([
            'nama' => 'Budi Terenkripsi',
            'hubungan' => 'ayah',
            'no_hp' => '081234567890',
            'email' => 'budi@example.com',
        ]);

        $raw = DB::table('guardians')->where('id', $guardian->id)->first();

        $this->assertNotSame('budi@example.com', $raw->email, 'email wajib tersimpan sebagai ciphertext');
        $this->assertNotEmpty($raw->email_hash);

        // Reads decrypt transparently; lookups ride the blind index.
        $this->assertSame('budi@example.com', $guardian->fresh()->email);
        $this->assertSame($guardian->id, Guardian::findByEncrypted('email', 'budi@example.com')->id);
    }

    public function test_a_unit_move_is_refused_while_an_enrollment_is_live(): void
    {
        $student = Student::create([
            'nama_lengkap' => 'Aisyah Pindah Unit', 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->sd->id, 'entry_year_id' => $this->year->id, 'status' => 'active',
        ]);
        $classroom = Classroom::create([
            'school_unit_id' => $this->sd->id, 'academic_year_id' => $this->year->id,
            'name' => '5-A', 'tingkat' => 5,
        ]);
        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $classroom->id,
            'academic_year_id' => $this->year->id, 'status' => 'active', 'joined_on' => '2026-07-01',
        ]);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/admin/students/{$student->ulid}", ['school_unit_ulid' => $this->smp->ulid]);

        $response->assertStatus(422);
        $this->assertStringContainsString('kenaikan kelas', (string) $response->json('message'));
        $this->assertSame($this->sd->id, $student->fresh()->school_unit_id, 'unit tidak berubah');

        // Without a live enrollment the move is a plain data fix - allowed.
        $loose = Student::create([
            'nama_lengkap' => 'Belum Berkelas', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sd->id, 'entry_year_id' => $this->year->id, 'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/students/{$loose->ulid}", ['school_unit_ulid' => $this->smp->ulid])
            ->assertOk();

        $this->assertSame($this->smp->id, $loose->fresh()->school_unit_id);
    }

    public function test_the_dead_chart_endpoints_are_gone(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/dashboard/billing-chart')->assertStatus(404);
        $this->actingAs($this->admin)->getJson('/api/admin/dashboard/achievements-chart')->assertStatus(404);

        // The living summary stays, and the attendance report the Laporan
        // screen now reads is reachable.
        $this->actingAs($this->admin)->getJson('/api/admin/dashboard/summary')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/admin/reports/attendance')->assertOk();
    }

    /**
     * P4-13, the automated half: walk the whole rapor chain once - teacher
     * files grades through the API, then wali AND admin both pull the PDF.
     * The human half (does the printout read right) stays with the school,
     * per PANDUAN-UJI section G.
     */
    public function test_the_rapor_chain_walks_end_to_end(): void
    {
        $term = \App\Models\Term::create([
            'academic_year_id' => $this->year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);

        $guru = User::create([
            'name' => 'Guru Kelas', 'email' => 'guru'.uniqid().'@yapinet.id',
            'role' => 'guru', 'school_unit_id' => $this->sd->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
        $classroom = Classroom::create([
            'school_unit_id' => $this->sd->id, 'academic_year_id' => $this->year->id,
            'name' => '6-RAPOR', 'tingkat' => 6,
        ]);
        $subject = \App\Models\Subject::create(['code' => 'MTK-'.uniqid(), 'name' => 'Matematika']);
        \App\Models\ClassSchedule::create([
            'classroom_id' => $classroom->id, 'subject_id' => $subject->id, 'teacher_id' => $guru->id,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
        ]);

        $student = Student::create([
            'nama_lengkap' => 'Aisyah Rapor', 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->sd->id, 'entry_year_id' => $this->year->id, 'status' => 'active',
        ]);
        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $classroom->id,
            'academic_year_id' => $this->year->id, 'status' => 'active', 'joined_on' => '2026-07-01',
        ]);

        $wali = User::create([
            'name' => 'Wali Rapor', 'email' => 'wali'.uniqid().'@yapinet.id',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);
        $guardian = Guardian::create(['user_id' => $wali->id, 'nama' => 'Wali Rapor', 'hubungan' => 'ibu']);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ibu', 'is_primary' => true]);

        foreach (['tugas' => 85, 'uts' => 90, 'uas' => 95] as $category => $score) {
            $this->actingAs($guru)
                ->postJson("/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades", [
                    'category' => $category,
                    'entries' => [['student_ulid' => $student->ulid, 'score' => $score]],
                ])
                ->assertCreated();
        }

        $waliPdf = $this->actingAs($wali)->get("/api/wali/students/{$student->ulid}/rapor");
        $waliPdf->assertOk();
        $this->assertStringStartsWith('%PDF', $waliPdf->getContent());
        $this->assertStringContainsString('Rapor-', (string) $waliPdf->headers->get('Content-Disposition'));
        $this->assertGreaterThan(10000, strlen($waliPdf->getContent()), 'PDF berisi konten, bukan kerangka kosong');

        $this->actingAs($this->admin)
            ->get("/api/admin/students/{$student->ulid}/rapor")
            ->assertOk();

        $this->actingAs($guru)
            ->get("/api/guru/students/{$student->ulid}/rapor")
            ->assertOk();
    }
}
