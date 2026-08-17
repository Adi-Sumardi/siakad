<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Run by the `scheduler` container (php artisan schedule:work). Fase 2 adds the
| SPP generator, the overdue sweep, and payment reminders here.
|
*/

// Keeps the unit master in step with PMB. Daily is often enough: units change
// once a year at most, but a stale code means a handoff for a new unit fails
// with "Unit tidak dikenal" until someone notices.
Schedule::command('units:sync')
    ->dailyAt('03:00')
    ->name('sync-school-units')
    ->withoutOverlapping()
    ->description('Sinkronkan master unit dari PMB');
