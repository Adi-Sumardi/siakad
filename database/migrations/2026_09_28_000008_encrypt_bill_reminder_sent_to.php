<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Audit 2026-09-28: the same contact list notification_logs.recipient
        // was encrypted for, in the other home it lives in. Widen for
        // ciphertext, then backfill raw (where('id', ...), never whereKey -
        // the query builder's __call turns that unknown method into
        // WHERE key = ? and the update silently matches nothing).
        Schema::table('bill_reminders', function (Blueprint $table) {
            $table->text('sent_to')->change();
        });

        $encrypter = app(\App\Services\Security\FieldEncrypter::class);

        DB::table('bill_reminders')
            ->whereNotNull('sent_to')
            ->orderBy('id')
            ->each(function ($row) use ($encrypter) {
                // Tolerant read: rows written after the model cast landed
                // are already ciphertext and must not be double-encrypted.
                if ($encrypter->decrypt($row->sent_to) === $row->sent_to) {
                    DB::table('bill_reminders')->where('id', $row->id)->update([
                        'sent_to' => $encrypter->encrypt($row->sent_to),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // One-way by design.
    }
};
