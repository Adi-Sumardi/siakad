<?php

namespace App\Http\Requests\Guru;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * A teacher-submitted achievement - lands as pending until an admin
 * verifies it (and decides the points), never straight into the ledger.
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
            'student_ulid' => 'required|string',
            'nama_prestasi' => 'required|string|max:200',
            'kategori' => 'required|in:Akademik,Non-Akademik,Olahraga,Seni,Lainnya',
            'tingkat' => 'required|in:Kelas,Sekolah,Kecamatan,Kabupaten/Kota,Provinsi,Nasional,Internasional',
            'juara' => 'nullable|in:1,2,3,Harapan 1,Harapan 2,Harapan 3,Peserta',
            'nama_event' => 'nullable|string|max:200',
            'penyelenggara' => 'nullable|string|max:200',
            // Laravel's bare 'today' keyword resolves against
            // config('app.timezone'), which is UTC despite .env setting
            // Asia/Jakarta (config/app.php never reads the env var) - an
            // explicit Jakarta date avoids rejecting a same-day event as
            // "in the future" during the seven hours every morning UTC's
            // calendar date still lags Jakarta's.
            'tanggal_event' => ['nullable', 'date', 'before_or_equal:'.Carbon::today('Asia/Jakarta')->toDateString()],
            'tempat_event' => 'nullable|string|max:200',
            'sertifikat' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'foto_kegiatan' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'points_awarded' => 'nullable|integer|min:1|max:200',
        ];
    }
}
