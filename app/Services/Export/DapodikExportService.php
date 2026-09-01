<?php

namespace App\Services\Export;

use App\Models\Guardian;
use App\Models\Student;
use Generator;
use Illuminate\Support\Collection;

/**
 * Lays out one row per student in the exact column order of "Formulir
 * Peserta Didik" (F-PD), the official Dapodik data-collection form - not an
 * import into Dapodik itself, which has no public write API. This speeds up
 * an operator sekolah's manual re-entry: roughly half of F-PD's 50 fields
 * have no source anywhere in Siakad's schema and are marked '[isi manual]'
 * rather than left silently blank, so a blank cell always means "not
 * collected here", never "checked, nothing there".
 */
class DapodikExportService
{
    private const MANUAL = '[isi manual]';

    private const AGAMA_CODE = [
        'islam' => '01',
        'kristen' => '02',
        'kristen/protestan' => '02',
        'protestan' => '02',
        'katholik' => '03',
        'katolik' => '03',
        'hindu' => '04',
        'budha' => '05',
        'buddha' => '05',
        'khonghucu' => '06',
    ];

    /** @return string[] */
    public function headers(): array
    {
        return [
            'Nama Lengkap', 'Jenis Kelamin', 'NISN', 'NIK/No. KITAS', 'No KK',
            'Tempat Lahir', 'Tanggal Lahir', 'No Registrasi Akta Lahir', 'Agama & Kepercayaan',
            'Kewarganegaraan', 'Berkebutuhan Khusus', 'Alamat Jalan', 'RT', 'RW', 'Nama Dusun',
            'Nama Kelurahan/Desa', 'Kecamatan', 'Kode Pos', 'Lintang', 'Bujur', 'Tempat Tinggal',
            'Moda Transportasi', 'Anak Keberapa', 'Pekerjaan (Warga Belajar)', 'Punya KIP',
            'Nama Ayah Kandung', 'NIK Ayah', 'Tahun Lahir Ayah', 'Pendidikan Ayah', 'Pekerjaan Ayah',
            'Penghasilan Bulanan Ayah', 'Nama Ibu Kandung', 'NIK Ibu', 'Tahun Lahir Ibu',
            'Pendidikan Ibu', 'Pekerjaan Ibu', 'Penghasilan Bulanan Ibu', 'Nama Wali', 'NIK Wali',
            'Tahun Lahir Wali', 'Pendidikan Wali', 'Pekerjaan Wali', 'Penghasilan Bulanan Wali',
            'Nomor Telepon Rumah', 'Nomor HP', 'Email',
            'Tinggi Badan (cm)', 'Berat Badan (kg)', 'Lingkar Kepala (cm)',
            'Jenis Pendaftaran', 'NIS/Nomor Induk PD', 'Tanggal Masuk Sekolah', 'Sekolah Asal',
        ];
    }

    /**
     * @param  Collection<int, Student>  $students
     * @return Generator<int, array>
     */
    public function rows(Collection $students): Generator
    {
        foreach ($students as $student) {
            yield $this->row($student);
        }
    }

    private function row(Student $student): array
    {
        $ayah = $this->guardianByRelationship($student, 'ayah');
        $ibu = $this->guardianByRelationship($student, 'ibu');
        $wali = $this->guardianByRelationship($student, 'wali');
        $billingContact = $student->guardians->first(fn (Guardian $g) => $g->pivot->is_billing_contact)
            ?? $student->guardians->first();

        return [
            $student->nama_lengkap,
            $student->jenis_kelamin === 'L' ? 'Laki-laki' : ($student->jenis_kelamin === 'P' ? 'Perempuan' : self::MANUAL),
            $student->nisn ?: '',
            $student->nik ?: '',
            self::MANUAL, // No KK
            $student->tempat_lahir ?: self::MANUAL,
            $student->tanggal_lahir?->format('d-m-Y') ?: self::MANUAL,
            self::MANUAL, // No Registrasi Akta Lahir
            $this->agamaCode($student->agama),
            $student->kewarganegaraan ?: self::MANUAL,
            self::MANUAL, // Berkebutuhan Khusus
            $student->alamat_lengkap ?: self::MANUAL,
            $student->rt ?: self::MANUAL,
            $student->rw ?: self::MANUAL,
            self::MANUAL, // Nama Dusun
            $student->kelurahan ?: self::MANUAL,
            $student->kecamatan ?: self::MANUAL,
            $student->kode_pos ?: self::MANUAL,
            self::MANUAL, // Lintang
            self::MANUAL, // Bujur
            self::MANUAL, // Tempat Tinggal
            self::MANUAL, // Moda Transportasi
            self::MANUAL, // Anak Keberapa
            self::MANUAL, // Pekerjaan (Warga Belajar)
            self::MANUAL, // Punya KIP

            $ayah?->nama ?? self::MANUAL,
            self::MANUAL, // NIK Ayah
            self::MANUAL, // Tahun Lahir Ayah
            self::MANUAL, // Pendidikan Ayah
            $ayah?->pekerjaan ?: self::MANUAL,
            $ayah?->penghasilan_bulanan ?: self::MANUAL,

            $ibu?->nama ?? self::MANUAL,
            self::MANUAL, // NIK Ibu
            self::MANUAL, // Tahun Lahir Ibu
            self::MANUAL, // Pendidikan Ibu
            $ibu?->pekerjaan ?: self::MANUAL,
            $ibu?->penghasilan_bulanan ?: self::MANUAL,

            $wali?->nama ?? self::MANUAL,
            self::MANUAL, // NIK Wali
            self::MANUAL, // Tahun Lahir Wali
            self::MANUAL, // Pendidikan Wali
            $wali?->pekerjaan ?: self::MANUAL,
            $wali?->penghasilan_bulanan ?: self::MANUAL,

            $billingContact?->no_hp ?: self::MANUAL,
            $billingContact?->no_hp ?: self::MANUAL,
            $billingContact?->email ?: self::MANUAL,

            self::MANUAL, // Tinggi Badan
            self::MANUAL, // Berat Badan
            self::MANUAL, // Lingkar Kepala

            self::MANUAL, // Jenis Pendaftaran
            $student->nis ?: self::MANUAL,
            $this->tanggalMasuk($student),
            self::MANUAL, // Sekolah Asal
        ];
    }

    private function guardianByRelationship(Student $student, string $relationship): ?Guardian
    {
        return $student->guardians->first(fn (Guardian $g) => $g->pivot->relationship === $relationship);
    }

    private function agamaCode(?string $agama): string
    {
        if (! $agama) {
            return self::MANUAL;
        }

        $code = self::AGAMA_CODE[mb_strtolower(trim($agama))] ?? null;

        // Passed through raw rather than guessed, so an operator sees exactly
        // what Siakad has on file instead of a silently wrong code.
        return $code ?? "{$agama} [cek kode]";
    }

    private function tanggalMasuk(Student $student): string
    {
        $date = $student->entryYear?->starts_on ?? $student->enrollments->min('joined_on');

        return $date ? \Illuminate\Support\Carbon::parse($date)->format('d-m-Y') : self::MANUAL;
    }
}
