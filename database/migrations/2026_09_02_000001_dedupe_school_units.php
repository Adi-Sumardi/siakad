<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * school_units carried two seedings of the same eight real campuses:
     * uppercase codes (RA-SAKINAH..SMA-48) with inconsistent labels ("SD
     * Islam Al Azhar 13 Rawamangun", "Playgroup (PG) Sakinah", "SMP Islam
     * Al Azhar 55 Jatiasih") and lowercase codes (ra-sakinah..sma-48) with
     * the labels the school confirmed correct: RA Sakinah, Playgroup
     * Sakinah, TK/SD/SMP/SMA Islam Al Azhar 13/12/55/33/48, with no campus
     * suffix. Keeps the lowercase batch - moving the handful of real rows
     * that pointed at the old batch across first so nothing is orphaned -
     * then removes the duplicates and renumbers sort_order to the order
     * the school gave: RA, Playgroup, TK, SD, SMP 12, SMP 55, SMA 33, SMA 48.
     */
    public function up(): void
    {
        $oldToNew = [
            'RA-SAKINAH' => 'ra-sakinah',
            'PG-SAKINAH' => 'pg-sakinah',
            'TK-13' => 'tk-13',
            'SD-13' => 'sd-13',
            'SMP-12' => 'smp-12',
            'SMP-55' => 'smp-55',
            'SMA-33' => 'sma-33',
            'SMA-48' => 'sma-48',
        ];

        $units = DB::table('school_units')
            ->whereIn('code', [...array_keys($oldToNew), ...array_values($oldToNew)])
            ->get(['id', 'code'])
            ->keyBy('code');

        foreach ($oldToNew as $oldCode => $newCode) {
            $old = $units->get($oldCode);
            $new = $units->get($newCode);

            // Nothing to migrate on a database that only ever seeded one
            // batch (a fresh install, or one already deduped).
            if (! $old || ! $new) {
                continue;
            }

            foreach (['students', 'fee_rates', 'classrooms'] as $table) {
                DB::table($table)->where('school_unit_id', $old->id)->update(['school_unit_id' => $new->id]);
            }

            DB::table('school_units')->where('id', $old->id)->delete();
        }

        foreach (array_values($oldToNew) as $i => $newCode) {
            DB::table('school_units')->where('code', $newCode)->update(['sort_order' => $i]);
        }
    }

    public function down(): void
    {
        // A data cleanup, not a schema change - the duplicate rows and
        // their original ids are gone, so there is nothing meaningful to
        // restore them to.
    }
};
