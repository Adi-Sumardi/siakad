<?php

namespace App\Support;

/**
 * Log Aktivitas in words (2026-10-01). Two keys lead here:
 *  - the dotted action an explicit ActivityLog::record() call writes
 *    ("bill.cancelled"), and
 *  - "Controller@method" for an admin request no explicit call covered,
 *    which LogAdminActivity records on its own so nothing goes missing.
 * Anything unlisted still gets a label built from its key.
 */
final class ActivityCatalog
{
    /** @var array<string, array{0: string, 1: string}> action => [label, category] */
    private const ACTIONS = [
        'auth.otp_login' => ['Masuk ke aplikasi', 'Akun'],
        'user.created' => ['Membuat akun user', 'User'],
        'user.updated' => ['Mengubah akun user', 'User'],
        'user.deleted' => ['Menghapus akun user', 'User'],
        'user.reset_access_issued' => ['Mereset akses login user', 'User'],
        'users.imported' => ['Import akun user', 'User'],
        'student.updated' => ['Mengubah data siswa', 'Siswa'],
        'student.deleted' => ['Menghapus data siswa', 'Siswa'],
        'students.imported' => ['Import data siswa', 'Siswa'],
        'promotion.executed' => ['Menjalankan kenaikan kelas', 'Siswa'],
        'classroom.created' => ['Membuat kelas', 'Akademik'],
        'classroom.updated' => ['Mengubah kelas', 'Akademik'],
        'classroom.deleted' => ['Menghapus kelas', 'Akademik'],
        'class_schedule.created' => ['Menambah jadwal pelajaran', 'Akademik'],
        'class_schedule.updated' => ['Mengubah jadwal pelajaran', 'Akademik'],
        'class_schedule.deleted' => ['Menghapus jadwal pelajaran', 'Akademik'],
        'subject.created' => ['Menambah mata pelajaran', 'Akademik'],
        'subject.updated' => ['Mengubah mata pelajaran', 'Akademik'],
        'subject.deleted' => ['Menghapus mata pelajaran', 'Akademik'],
        'term.created' => ['Membuat semester', 'Akademik'],
        'term.activated' => ['Mengaktifkan semester', 'Akademik'],
        'grade.recorded_bulk' => ['Mengisi nilai', 'Akademik'],
        'attendance.session_opened' => ['Membuka sesi presensi', 'Presensi'],
        'attendance.session_completed' => ['Menutup sesi presensi', 'Presensi'],
        'attendance.session_record_revoked' => ['Membatalkan catatan presensi', 'Presensi'],
        'extracurricular.created' => ['Membuat ekstrakurikuler', 'Ekskul'],
        'extracurricular.updated' => ['Mengubah ekstrakurikuler', 'Ekskul'],
        'extracurricular.member_assigned' => ['Menambah anggota ekskul', 'Ekskul'],
        'extracurricular.member_removed' => ['Mengeluarkan anggota ekskul', 'Ekskul'],
        'extracurricular.self_enrolled' => ['Mendaftar ekskul', 'Ekskul'],
        'point.recorded' => ['Mencatat poin', 'Poin'],
        'point.recorded_bulk' => ['Mencatat poin (massal)', 'Poin'],
        'point.revoked' => ['Membatalkan poin', 'Poin'],
        'point_rule.created' => ['Menambah aturan poin', 'Poin'],
        'point_rule.updated' => ['Mengubah aturan poin', 'Poin'],
        'point_rule.deleted' => ['Menghapus aturan poin', 'Poin'],
        'point_threshold.created' => ['Menambah ambang poin', 'Poin'],
        'point_threshold.updated' => ['Mengubah ambang poin', 'Poin'],
        'bill.manual_created' => ['Membuat tagihan manual', 'Keuangan'],
        'bill.waived' => ['Membebaskan tagihan', 'Keuangan'],
        'bill.cancelled' => ['Membatalkan tagihan', 'Keuangan'],
        'billing_run.executed' => ['Generate tagihan', 'Keuangan'],
        'payment.va_issued' => ['Membuat Virtual Account', 'Keuangan'],
        'fee_type.created' => ['Menambah jenis biaya', 'Tarif & Diskon'],
        'fee_type.updated' => ['Mengubah jenis biaya', 'Tarif & Diskon'],
        'fee_type.deleted' => ['Menghapus jenis biaya', 'Tarif & Diskon'],
        'fee_rate.created' => ['Menambah tarif', 'Tarif & Diskon'],
        'fee_rate.updated' => ['Mengubah tarif', 'Tarif & Diskon'],
        'fee_rate.deleted' => ['Menghapus tarif', 'Tarif & Diskon'],
        'fee_rates.imported' => ['Import tarif', 'Tarif & Diskon'],
        'discount_scheme.created' => ['Menambah skema diskon', 'Tarif & Diskon'],
        'discount_scheme.updated' => ['Mengubah skema diskon', 'Tarif & Diskon'],
        'discount_scheme.deleted' => ['Menghapus skema diskon', 'Tarif & Diskon'],
        'student_discount.assigned' => ['Memberi diskon siswa', 'Tarif & Diskon'],
        'student_discount.revoked' => ['Mencabut diskon siswa', 'Tarif & Diskon'],
        'school_unit.created' => ['Menambah unit sekolah', 'Pengaturan'],
        'school_unit.updated' => ['Mengubah unit sekolah', 'Pengaturan'],
        'school_unit.deleted' => ['Menghapus unit sekolah', 'Pengaturan'],
        'notification.resent' => ['Mengirim ulang notifikasi', 'Sistem'],
        'integration_event.reprocessed' => ['Memproses ulang data PMB', 'Sistem'],

        // Admin requests no explicit record() covers - see LogAdminActivity.
        'BillController@storeVa' => ['Membuat Virtual Account', 'Keuangan'],
        'BillController@pdf' => ['Mengunduh PDF tagihan', 'Unduhan'],
        'StudentController@exportDapodik' => ['Export data Dapodik', 'Unduhan'],
        'ImportController@downloadUserTemplate' => ['Mengunduh template import user', 'Unduhan'],
        'ImportController@downloadStudentTemplate' => ['Mengunduh template import siswa', 'Unduhan'],
        'ImportController@downloadFeeRateTemplate' => ['Mengunduh template import tarif', 'Unduhan'],
        'AchievementController@verify' => ['Memverifikasi prestasi', 'Prestasi'],
        'AchievementController@reject' => ['Menolak prestasi', 'Prestasi'],
        'AnnouncementController@store' => ['Membuat pengumuman', 'Informasi'],
        'AnnouncementController@update' => ['Mengubah pengumuman', 'Informasi'],
        'AnnouncementController@destroy' => ['Menghapus pengumuman', 'Informasi'],
        'DailyAttendanceSettingController@update' => ['Mengubah pengaturan presensi harian', 'Presensi'],
        'DailyAttendanceSettingController@issuePublicLink' => ['Membuat link presensi harian', 'Presensi'],
        'DailyAttendanceSettingController@resetPublicLink' => ['Mereset link presensi harian', 'Presensi'],
        'DailyAttendanceSessionController@mark' => ['Mengisi presensi harian', 'Presensi'],
        'ReferenceController@storeAcademicYear' => ['Menambah tahun ajaran', 'Akademik'],
        'ReferenceController@activateAcademicYear' => ['Mengaktifkan tahun ajaran', 'Akademik'],
        'HolidayController@store' => ['Menambah hari libur', 'Akademik'],
        'HolidayController@destroy' => ['Menghapus hari libur', 'Akademik'],
    ];

    /** Requests that change nothing - never logged by the safety net. */
    public const IGNORED = [
        'BillingRunController@preview',
    ];

    /** @return array{label: string, category: string} */
    public static function describe(string $key): array
    {
        if (isset(self::ACTIONS[$key])) {
            [$label, $category] = self::ACTIONS[$key];

            return ['label' => $label, 'category' => $category];
        }

        // "SchoolUnitController@store" -> "Menambah unit sekolah".
        if (preg_match('/^(?:admin\.)?(\w+)Controller@(\w+)$/', $key, $m)) {
            $noun = self::NOUNS[$m[1]] ?? strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $m[1]));
            $verb = match (true) {
                str_starts_with($m[2], 'store') || str_starts_with($m[2], 'create') => 'Menambah',
                str_starts_with($m[2], 'update') => 'Mengubah',
                str_starts_with($m[2], 'destroy') || str_starts_with($m[2], 'delete') => 'Menghapus',
                str_starts_with($m[2], 'import') => 'Import',
                str_starts_with($m[2], 'download') || str_starts_with($m[2], 'export') || $m[2] === 'pdf' => 'Mengunduh',
                default => 'Menjalankan '.strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $m[2])).' -',
            };

            return ['label' => "{$verb} {$noun}", 'category' => 'Lainnya'];
        }

        return ['label' => ucfirst(str_replace(['.', '_'], ' ', $key)), 'category' => 'Lainnya'];
    }

    private const NOUNS = [
        'SchoolUnit' => 'unit sekolah', 'Classroom' => 'kelas', 'Subject' => 'mata pelajaran',
        'Student' => 'siswa', 'User' => 'user', 'FeeSetting' => 'tarif', 'Discount' => 'diskon',
        'Bill' => 'tagihan', 'BillingRun' => 'generate tagihan', 'PointRule' => 'aturan poin',
        'PointThreshold' => 'ambang poin', 'Extracurricular' => 'ekskul', 'Schedule' => 'jadwal pelajaran',
        'Promotion' => 'kenaikan kelas', 'Term' => 'semester', 'Reference' => 'data referensi',
        'Import' => 'data', 'NotificationLog' => 'notifikasi', 'IntegrationEvent' => 'data PMB',
        'Holiday' => 'hari libur', 'Announcement' => 'pengumuman', 'Achievement' => 'prestasi',
        'DailyAttendanceSetting' => 'pengaturan presensi harian', 'DailyAttendanceSession' => 'presensi harian',
    ];

    /** @return list<string> */
    public static function categories(): array
    {
        return ['Keuangan', 'Tarif & Diskon', 'Siswa', 'Akademik', 'Presensi', 'Poin', 'Prestasi', 'Ekskul', 'Informasi', 'User', 'Pengaturan', 'Unduhan', 'Akun', 'Sistem', 'Lainnya'];
    }
}
