<?php

namespace App\Http\Requests\Wali;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * A wali reporting their child's out-of-school achievement - lands as
 * pending for admin verification, points never set here (unlike the
 * guru-submitted shape).
 */
class StoreAchievementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_prestasi' => 'required|string|max:200',
            'kategori' => 'required|in:Akademik,Non-Akademik,Olahraga,Seni,Lainnya',
            'tingkat' => 'required|in:Kelas,Sekolah,Kecamatan,Kabupaten/Kota,Provinsi,Nasional,Internasional',
            'juara' => 'nullable|in:1,2,3,Harapan 1,Harapan 2,Harapan 3,Peserta',
            'nama_event' => 'nullable|string|max:200',
            'penyelenggara' => 'nullable|string|max:200',
            // An explicit Jakarta date rather than Laravel's bare 'today'
            // keyword: 'today' resolves against app.timezone (env-read since
            // 16 Sep 2026, default Jakarta), and the explicit form stays
            // correct even if that env ever flips back to UTC, which would
            // reject a same-day event as "in the future" before 07:00 WIB.
            'tanggal_event' => ['nullable', 'date', 'before_or_equal:'.Carbon::today('Asia/Jakarta')->toDateString()],
            'tempat_event' => 'nullable|string|max:200',
            'sertifikat' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'foto_kegiatan' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
        ];
    }
}
