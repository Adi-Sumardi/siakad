<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // T51-b: PMB handoff payloads carry children's/guardians' PII.
        // The column already has no length limit (json/text), so this is a
        // pure backfill. FORMAT MATTERS: the model reads payload through
        // the native `encrypted:array` cast, which is Crypt::encryptString
        // (no serialize layer) over the JSON string - FieldEncrypter's
        // serialize-based output would read back as garbage here.
        DB::table('integration_events')
            ->whereNotNull('payload')
            ->orderBy('id')
            ->each(function ($row) {
                DB::table('integration_events')
                    ->where('id', $row->id)
                    ->update(['payload' => Crypt::encryptString($row->payload)]);
            });
    }

    public function down(): void
    {
        // One-way by design.
    }
};
