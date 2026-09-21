<?php

namespace App\Services\Academic;

use App\Models\Student;
use App\Models\Term;
use App\Services\Attendance\DailyAttendanceService;
use App\Services\Points\PointLedger;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders one student's report card as PDF - grades plus the attendance and
 * point summaries those two features already compute, so a rapor reads as
 * one coherent picture instead of three separate exports. Same
 * generate-on-request shape as BillPdfService: nothing is stored, this
 * builds the PDF fresh from grades/daily_records/point_records every
 * time it's requested. Attendance counts DAYS (the daily layer, §8) -
 * "Hadir 120 hari", never lesson periods.
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
            'kelas' => $this->classroomNameFor($student, $term),
            'subjects' => $grades->summaryForRapor($student, $term),
            'attendance' => app(DailyAttendanceService::class)->summary($student, $term),
            'pointBalance' => app(PointLedger::class)->balance($student, $term),
            'schoolName' => config('app.name'),
            'logoBase64' => $logoBase64,
        ])->setPaper('a4');
    }

    /**
     * The class the student sat in during THIS term's year - not whatever
     * class they are in today. A historical print used to wear the current
     * class on its kop (an old "7-A" report introducing the student as "8-A").
     * After a promotion the old enrollment row is closed ('promoted') but
     * still names that year's classroom, so any status matches and the
     * latest joined_on breaks a tie.
     */
    public function classroomNameFor(Student $student, Term $term): ?string
    {
        return $student->enrollments()
            ->where('academic_year_id', $term->academic_year_id)
            ->latest('joined_on')
            ->first()
            ?->classroom
            ?->name;
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
