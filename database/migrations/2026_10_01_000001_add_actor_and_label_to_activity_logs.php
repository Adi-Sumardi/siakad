<?php

use App\Support\ActivityCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log Aktivitas (2026-10-01): who did it is copied onto the row as it was at
 * the time - name, role, unit - so renaming or moving an admin never rewrites
 * history; the action is stored in words with its category; status records
 * whether the request went through (null = an explicit record, which only
 * ever runs on success). Existing rows get the same columns filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('user_name')->nullable()->after('user_id');
            $table->string('role', 20)->nullable()->after('user_name');
            $table->foreignId('school_unit_id')->nullable()->after('role')->constrained('school_units')->nullOnDelete();
            $table->string('unit_label')->nullable()->after('school_unit_id');
            $table->string('label')->nullable()->after('action');
            $table->string('category', 40)->nullable()->after('label');
            $table->unsignedSmallInteger('status')->nullable()->after('category');
            $table->string('path', 500)->nullable()->after('status');

            $table->index(['school_unit_id', 'created_at']);
            $table->index(['category', 'created_at']);
        });

        $users = DB::table('users')
            ->leftJoin('school_units', 'school_units.id', '=', 'users.school_unit_id')
            ->get(['users.id', 'users.name', 'users.role', 'users.school_unit_id', 'school_units.label as unit_label'])
            ->keyBy('id');

        DB::table('activity_logs')->orderBy('id')->chunkById(500, function ($rows) use ($users) {
            foreach ($rows as $row) {
                $user = $row->user_id ? $users->get($row->user_id) : null;
                $described = ActivityCatalog::describe($row->action);

                DB::table('activity_logs')->where('id', $row->id)->update([
                    'user_name' => $user?->name,
                    'role' => $user?->role,
                    'school_unit_id' => $user?->school_unit_id,
                    'unit_label' => $user?->unit_label,
                    'label' => $described['label'],
                    'category' => $described['category'],
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['school_unit_id', 'created_at']);
            $table->dropIndex(['category', 'created_at']);
            $table->dropConstrainedForeignId('school_unit_id');
            $table->dropColumn(['user_name', 'role', 'unit_label', 'label', 'category', 'status', 'path']);
        });
    }
};
