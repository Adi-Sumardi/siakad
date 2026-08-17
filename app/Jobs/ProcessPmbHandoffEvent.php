<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Services\Handoff\PmbHandoffProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPmbHandoffEvent implements ShouldQueue
{
    use Queueable;

    /**
     * Retries cover a transient failure - the mail gateway refusing a
     * connection, a deadlock. They do not cover a payload naming a unit that
     * does not exist: that throws, burns the attempts, and lands in
     * integration_events with status 'failed' and the reason, which is where an
     * admin can see and fix it.
     */
    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public int $eventId) {}

    public function handle(PmbHandoffProcessor $processor): void
    {
        $event = IntegrationEvent::find($this->eventId);

        if (! $event) {
            return;
        }

        $processor->process($event);
    }
}
