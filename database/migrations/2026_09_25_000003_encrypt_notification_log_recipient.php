<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ciphertext does not fit the original varchar(255) for longer
        // addresses (audit T51-b) - widen first, same precedent as
        // guardians.email at the 80ca05c merge.
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->text('recipient')->change();
        });

        // Backfill, raw on purpose: the model now DECRYPTS on read, so the
        // still-plaintext rows cannot pass back through Eloquent - the same
        // constraint the guardians.email backfill documented.
        $encrypter = app(\App\Services\Security\FieldEncrypter::class);

        DB::table('notification_logs')
            ->whereNotNull('recipient')
            ->orderBy('id')
            ->each(function ($row) use ($encrypter) {
                DB::table('notification_logs')
                    ->whereKey($row->id)
                    ->update(['recipient' => $encrypter->encrypt($row->recipient)]);
            });
    }

    public function down(): void
    {
        // One-way by design: the plaintext this column used to carry is
        // exactly what this migration exists to remove.
    }
};
