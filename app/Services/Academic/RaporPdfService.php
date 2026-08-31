<?php

namespace App\Services\Academic;

use App\Models\Student;
use App\Models\Term;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Points\PointLedger;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders one student's report card as PDF - grades plus the attendance and
 * point summaries those two features already compute, so a rapor reads as
 * one coherent picture instead of three separate exports. Same
 * generate-on-request shape as BillPdfService: nothing is stored, this
 * builds the PDF fresh from grades/attendance_records/point_records every
 * time it's requested.
 */
class RaporPdfService
{
    public function render(Student $student, Term $term, GradeService $grades): \Barryvdh\DomPDF\PDF
    {
        $student->loadMissing(['schoolUnit']);

        $logoPath = public_path('images/logo-yapi.png');
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $logoBase64 = 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath));
        }

        return Pdf::loadView('pdf.rapor', [
            'student' => $student,
            'term' => $term,
            'kelas' => $student->currentEnrollment()?->classroom?->name,
            'subjects' => $grades->summaryForRapor($student, $term),
            'attendance' => app(AttendanceLedger::class)->summary($student, $term),
            'pointBalance' => app(PointLedger::class)->balance($student, $term),
            'schoolName' => config('app.name'),
            'logoBase64' => $logoBase64,
        ])->setPaper('a4');
    }

    public function filename(Student $student, Term $term): string
    {
        // Content-Disposition rejects "/" in a filename, and Term::label()
        // includes the academic year in "2026/2027" form - the same reason
        // BillPdfService::filename() strips slashes from a bill_number.
        $safeName = str_replace(' ', '-', $student->nama_lengkap);
        $safeTerm = str_replace('/', '-', $term->label());

        return "Rapor-{$safeName}-{$safeTerm}.pdf";
    }
}
