<?php

namespace App\Console\Commands;

use App\Models\SchoolUnit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Pulls the unit master from PMB.
 *
 * The unit `code` is the contract every handoff payload is matched on, so
 * maintaining a second hand-typed list here would guarantee that the two drift
 * and that a student eventually arrives for a unit this app has never heard of.
 * PMB already publishes the list read-only at GET /api/school-units.
 */
class SyncSchoolUnits extends Command
{
    protected $signature = 'units:sync {--prune : Deactivate local units PMB no longer lists}';

    protected $description = 'Sinkronkan master unit sekolah dari PMB';

    public function handle(): int
    {
        $baseUrl = config('services.pmb.base_url');

        if (! $baseUrl) {
            $this->error('PMB_BASE_URL belum diset.');

            return self::FAILURE;
        }

        $response = Http::timeout(20)->acceptJson()->get(rtrim($baseUrl, '/').'/api/school-units');

        if ($response->failed()) {
            $this->error("Gagal menghubungi PMB: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $units = $response->json('data') ?? [];

        if (! $units) {
            $this->warn('PMB tidak mengembalikan unit apa pun. Tidak ada yang diubah.');

            return self::FAILURE;
        }

        $seen = [];

        foreach ($units as $index => $unit) {
            $record = SchoolUnit::updateOrCreate(
                ['code' => $unit['code']],
                [
                    'label' => $unit['label'],
                    'jenjang_group' => $unit['jenjang_group'] ?? null,
                    'sort_order' => $index,
                    'is_active' => true,
                ]
            );

            $seen[] = $record->code;
            $this->line(($record->wasRecentlyCreated ? '  + ' : '  · ').$record->code.' — '.$record->label);
        }

        if ($this->option('prune')) {
            // Deactivated, never deleted: students already point at these rows,
            // and a unit that closes still has alumni and unpaid bills.
            $stale = SchoolUnit::whereNotIn('code', $seen)->where('is_active', true)->update(['is_active' => false]);

            if ($stale) {
                $this->warn("{$stale} unit dinonaktifkan karena tidak lagi terdaftar di PMB.");
            }
        }

        $this->info(count($seen).' unit tersinkron.');

        return self::SUCCESS;
    }
}
