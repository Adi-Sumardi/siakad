<?php

use App\Models\AcademicYear;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AcademicYear::firstOrCreate(
            ['year' => '2027/2028'],
            ['starts_on' => '2027-07-01', 'ends_on' => '2028-06-30', 'is_active' => false]
        );

        AcademicYear::firstOrCreate(
            ['year' => '2028/2029'],
            ['starts_on' => '2028-07-01', 'ends_on' => '2029-06-30', 'is_active' => false]
        );
    }

    public function down(): void
    {
        AcademicYear::whereIn('year', ['2027/2028', '2028/2029'])->delete();
    }
};
