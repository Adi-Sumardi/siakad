<?php

use App\Models\FeeType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gates BillGenerator on extracurricular_members, the same way
     * requires_selection gates it on StudentFeeSelection for seragam.
     * 'ekskul' has been billed flatly to every student, every term, since
     * this app's very first seed - this migration is what stops that. See
     * BillGenerator::evaluate() and docs/06-ROADMAP.md.
     */
    public function up(): void
    {
        Schema::table('fee_types', function (Blueprint $table) {
            $table->boolean('requires_roster_membership')->default(false)->after('requires_selection');
        });

        FeeType::where('code', 'ekskul')->update(['requires_roster_membership' => true]);
    }

    public function down(): void
    {
        Schema::table('fee_types', function (Blueprint $table) {
            $table->dropColumn('requires_roster_membership');
        });
    }
};
