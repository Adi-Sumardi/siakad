<?php

namespace Tests\Feature;

use App\Jobs\SendQontakTemplateMessage;
use App\Models\NotificationLog;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\QontakWhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The template job's delivery guards (audit T42-a, T43, T44, T64-b). The
 * merge of 2026-10-26 replaced this job with an older copy and only the
 * log-only check noticed - these pin the rest.
 */
class QontakTemplateJobTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $status = 'queued'): NotificationLog
    {
        return NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'reminder_spp',
            'recipient' => '081200000700',
            'payload' => [],
            'status' => $status,
        ]);
    }

    private function job(?string $logUlid): SendQontakTemplateMessage
    {
        return new SendQontakTemplateMessage(
            phone: '081200000700',
            toName: 'Ibu Naila',
            templateId: 'tpl-reminder-spp',
            bodyValues: ['Naila', 'September 2026', '700.000'],
            notificationLogUlid: $logUlid,
        );
    }

    public function test_a_row_already_sent_is_never_sent_again(): void
    {
        // The sweep's re-queue arriving after the original job succeeded:
        // without the delivery claim the parent got the same WhatsApp twice.
        $gateway = Mockery::mock(QontakWhatsAppGateway::class);
        $gateway->shouldNotReceive('sendTemplate');

        $this->job($this->row('sent')->ulid)->handle($gateway);
    }

    public function test_a_successful_send_marks_the_row_and_releases_the_claim(): void
    {
        $row = $this->row();
        $gateway = Mockery::mock(QontakWhatsAppGateway::class);
        $gateway->shouldReceive('sendTemplate')->once()->andReturn(NotificationResult::ok());

        $this->job($row->ulid)->handle($gateway);

        $fresh = $row->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNull($fresh->error);
        $this->assertNull($fresh->claimed_at);
    }

    public function test_a_job_that_gives_up_marks_its_row_failed(): void
    {
        // Without failed() the row stayed 'queued' forever - invisible to
        // the retry sweep, the manual resend and the Monitoring page.
        $row = $this->row();
        $row->forceFill(['claimed_at' => now()])->save();

        $this->job($row->ulid)->failed(new RuntimeException('Qontak 500'));

        $fresh = $row->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('Qontak 500', $fresh->error);
        $this->assertNull($fresh->claimed_at);
    }

    public function test_rate_limit_releases_do_not_use_up_the_jobs_attempts(): void
    {
        // A burst of reminders is released by the rate limiter many times;
        // with a plain $tries=3 those releases killed the job silently.
        $job = $this->job(null);

        $this->assertSame(0, $job->tries);
        $this->assertTrue($job->retryUntil() > now()->addMinutes(30));
    }

    public function test_a_failed_send_logs_a_masked_number(): void
    {
        Log::spy();
        $gateway = Mockery::mock(QontakWhatsAppGateway::class);
        $gateway->shouldReceive('sendTemplate')->once()->andReturn(NotificationResult::fail('ditolak'));

        try {
            $this->job($this->row()->ulid)->handle($gateway);
            $this->fail('pengiriman gagal harus melempar supaya antrean mencoba ulang');
        } catch (RuntimeException) {
            // expected
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $context['phone'] === '****0700')
            ->once();
    }
}
