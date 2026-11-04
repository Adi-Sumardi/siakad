<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hanya uang pangkal yang boleh dicicil. SPP dan biaya lain dibayar penuh
 * per tagihan (beberapa bulan SPP boleh dibayar sekaligus, tapi tiap bulan
 * lunas penuh).
 */
return new class extends Migration
{
    public function up(): void
    {
        $pangkalIds = DB::table('fee_types')
            ->whereRaw('LOWER(code) LIKE ?', ['%pangkal%'])
            ->orWhereRaw('LOWER(name) LIKE ?', ['%pangkal%'])
            ->pluck('id');

        DB::table('fee_types')->update(['allow_installment' => false]);
        DB::table('fee_types')->whereIn('id', $pangkalIds)->update(['allow_installment' => true]);

        DB::table('bills')->whereNotIn('fee_type_id', $pangkalIds)->update(['allow_installment' => false]);

        // Jadwal cicilan yang belum dibayar pada tagihan non-pangkal tidak berlaku lagi.
        DB::table('installment_schedules')
            ->whereIn('bill_id', DB::table('bills')->whereNotIn('fee_type_id', $pangkalIds)->select('id'))
            ->where('status', '!=', 'paid')
            ->delete();
    }

    public function down(): void
    {
        // Sengaja tidak dikembalikan: aturan bisnisnya memang hanya uang pangkal.
    }
};
