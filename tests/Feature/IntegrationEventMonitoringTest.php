<?php

namespace Tests\Feature;

use App\Models\IntegrationEvent;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ruang kontrol's webhook-inbox leg (/admin/monitoring): the
 * integration_events rows, and the PMB replay. The replay's whole safety
 * story is that PmbHandoffProcessor re-guards isProcessed() and upserts
 * everything - these tests pin the classic flow (unknown unit fixed, event
 * reprocessed, handoff completes) and the refusals.
 */
class IntegrationEventMonitoringTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to: string, template: string, data: array}> */
    private array $sentMail = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(MailGateway::class, fn () => new class($this->sentMail) implements MailGateway
        {
            public function __construct(private array &$sent) {}

            public function send(string $to, string $template, array $data, array $attachments = []): NotificationResult
            {
                $this->sent[] = compact('to', 'template', 'data');

                return NotificationResult::ok();
            }
        });
    }

    private function staff(string $role): User
    {
        return User::create([
            'name' => ucfirst($role).uniqid(), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    /** The enrolled handoff shape from PmbHandoffTest, pointed at a unit that does not exist. */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'event' => 'student.enrolled',
            'event_id' => '01JCEVENT0000000000000001',
            'occurred_at' => now()->toIso8601String(),
            'student' => [
                'pmb_ulid' => '01JCSTUDENT000000000000001',
                'no_pendaftaran' => 'PMB-2026-00214',
                'nama_lengkap' => 'Aisyah Nur Ramadhani',
                'nama_panggilan' => 'Aisyah',
                'jenis_kelamin' => 'P',
                'tanggal_lahir' => '2014-03-11',
                'nisn' => '0123456789',
                'nik' => '3273010101140001',
                'unit_code' => 'SD-TIDAK-ADA',
                'academic_year' => '2026/2027',
            ],
            'guardians' => [
                [
                    'nama' => 'Budi Ramadhani',
                    'hubungan' => 'ayah',
                    'email' => 'budi@example.com',
                    'no_hp' => '081234567890',
                    'is_primary' => true,
                ],
            ],
        ], $overrides);
    }

    private function pmbEvent(array $overrides = []): IntegrationEvent
    {
        return IntegrationEvent::create(array_merge([
            'source' => 'pmb',
            'event_type' => 'student.enrolled',
            'event_id' => uniqid('evt-'),
            'payload' => $this->payload(),
            'status' => 'failed',
            'attempts' => 5,
            'error' => 'Unit tidak dikenal: SD-TIDAK-ADA. Tambahkan unit ini lebih dulu.',
        ], $overrides));
    }

    public function test_central_admin_lists_events_without_payloads(): void
    {
        $pmb = $this->pmbEvent();
        IntegrationEvent::create([
            'source' => 'billing_api',
            'event_type' => 'payment.callback',
            'event_id' => 'billing_api:inv-1:SETTLED',
            'payload' => ['id' => 'inv-1'],
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        $body = $this->actingAs($this->staff('admin'))
            ->getJson('/api/admin/integration-events?source=pmb')
            ->assertOk()
            ->json('events');

        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame($pmb->ulid, $body['data'][0]['ulid']);
        $this->assertSame('failed', $body['data'][0]['status']);
        $this->assertSame(5, $body['data'][0]['attempts']);
        $this->assertArrayNotHasKey('payload', $body['data'][0]);
        $this->assertNull($body['data'][0]['student_ulid']); // failed before a student existed
    }

    public function test_reprocess_completes_a_failed_event_once_the_blocker_is_fixed(): void
    {
        $event = $this->pmbEvent();

        // The admin fixes the cause the error told them about: the missing unit.
        SchoolUnit::create(['code' => 'SD-TIDAK-ADA', 'label' => 'SD Tidak Ada', 'jenjang_group' => 'sd']);

        $this->actingAs($this->staff('admin'))
            ->postJson("/api/admin/integration-events/{$event->ulid}/reprocess")
            ->assertStatus(202);

        // The queue runs sync in tests, so by the time the response is back
        // the handoff has completed: student upserted, event processed,
        // guardian invited.
        $fresh = $event->fresh();
        $this->assertSame('processed', $fresh->status);
        $this->assertNull($fresh->error);
        $this->assertNotNull($fresh->student_id);

        $student = Student::where('pmb_student_ulid', '01JCSTUDENT000000000000001')->first();
        $this->assertNotNull($student);
        $this->assertSame($student->id, $fresh->student_id);
        $this->assertCount(1, $this->sentMail);
        $this->assertSame('school_account_invite', $this->sentMail[0]['template']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'integration_event.reprocessed']);
    }

    public function test_reprocess_refuses_non_pmb_and_already_processed_events(): void
    {
        $bank = IntegrationEvent::create([
            'source' => 'billing_api',
            'event_type' => 'payment.callback',
            'event_id' => 'billing_api:inv-2:FAILED',
            'payload' => ['id' => 'inv-2'],
            'status' => 'failed',
            'attempts' => 1,
            'error' => 'Payment not found',
        ]);
        $done = $this->pmbEvent(['status' => 'processed', 'processed_at' => now(), 'error' => null]);
        $admin = $this->staff('admin');

        $this->actingAs($admin)->postJson("/api/admin/integration-events/{$bank->ulid}/reprocess")->assertStatus(422);
        $this->actingAs($admin)->postJson("/api/admin/integration-events/{$done->ulid}/reprocess")->assertStatus(422);

        $this->assertDatabaseHas('integration_events', ['ulid' => $bank->ulid, 'status' => 'failed']);
    }

    public function test_reprocess_also_picks_up_a_stuck_received_event(): void
    {
        SchoolUnit::create(['code' => 'SD-TIDAK-ADA', 'label' => 'SD Tidak Ada', 'jenjang_group' => 'sd']);
        $event = $this->pmbEvent(['status' => 'received', 'attempts' => 0, 'error' => null]);

        $this->actingAs($this->staff('admin'))
            ->postJson("/api/admin/integration-events/{$event->ulid}/reprocess")
            ->assertStatus(202);

        $this->assertSame('processed', $event->fresh()->status);
    }
}
