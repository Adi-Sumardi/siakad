<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The two seed/demo students (Muhammad Rayhan Pratama, Aisyah Putri
     * Azzahra - both created in the same second on 2026-08-20, from
     * DatabaseSeeder/TestPaymentSeeder) were the only rows in `students`
     * on production, and had left enough behind - 4 SPP bills, a shared
     * "Adi Sumardi" demo guardian, and two dummy classrooms (1-A, 7-A) -
     * that the "spp" FeeType could not be deleted (bills.fee_type_id is
     * ON DELETE RESTRICT, see FeeSettingController::destroyType()) and the
     * classrooms/guardian were cluttering otherwise-empty tables.
     *
     * Deleting the students cascades their bills (and bill_lines/
     * installment_schedules/payment_allocations/bill_reminders under
     * those), enrollments, student_guardians, student_documents, etc. -
     * see the FK check this was based on (confdeltype='c' for all of
     * those against students). Three things do not cascade from that and
     * are handled explicitly: `payments` (linked via payer_guardian_id,
     * not student_id), the now-orphaned demo guardian, and the two dummy
     * classrooms (nothing enrolls into them once the students are gone,
     * but the classroom rows themselves stay unless removed).
     *
     * Matched by name/number, not id, so this is a no-op anywhere these
     * specific rows do not exist (a fresh install, or one already cleaned).
     */
    public function up(): void
    {
        DB::table('payments')->whereIn('payment_number', [
            'PAY/20260820/3TKG7K',
            'PAY/20260820/7KOVXV',
            'PAY/20260820/VRZL2W',
        ])->delete();

        DB::table('students')->whereIn('nama_lengkap', [
            'Muhammad Rayhan Pratama',
            'Aisyah Putri Azzahra',
        ])->delete();

        // Only a guardian left with no children at all, matching the known
        // demo name - never a blanket "delete anyone with zero students",
        // which could catch a real guardian mid-registration.
        DB::table('guardians')
            ->where('nama', 'Adi Sumardi')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('student_guardians')
                ->whereColumn('student_guardians.guardian_id', 'guardians.id'))
            ->delete();

        DB::table('classrooms')->where(function ($q) {
            $q->where('name', '1-A')->orWhere('name', '7-A');
        })->delete();
    }

    public function down(): void
    {
        // Demo data cleanup, not a schema change - nothing to restore.
    }
};
