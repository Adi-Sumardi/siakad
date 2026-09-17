<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The unique index widens from (bill_id, kind) to (bill_id, kind,
        // channel): a beat is now remembered per channel, because a reminder
        // fans out to email AND WhatsApp separately and each delivery needs
        // its own "already happened" fact - an email that went out must not
        // block the WhatsApp nudge, and vice versa. The scheduler firing
        // twice still sends nothing twice; it just does so per channel.
        Schema::table('bill_reminders', function (Blueprint $table) {
            $table->dropUnique(['bill_id', 'kind']);
            $table->unique(['bill_id', 'kind', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::table('bill_reminders', function (Blueprint $table) {
            $table->dropUnique(['bill_id', 'kind', 'channel']);
            $table->unique(['bill_id', 'kind']);
        });
    }
};
