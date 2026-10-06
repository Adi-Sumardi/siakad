<?php

namespace App\Services\Academic;

use App\Models\ExtracurricularAssessment;
use App\Models\ExtracurricularAttendance;
use App\Models\ExtracurricularMember;
use App\Models\Student;
use App\Models\Term;

/**
 * One student's ekskul results for a term (audit 6 Okt 2026 #8): which
 * activities, the pembina's predikat, and practice attendance - the block
 * the rapor prints and the guardian portal shows.
 */
class ExtracurricularReport
{
    /**
     * @return list<array{name:string, pembina:?string, predikat:?string, predikat_label:?string, keterangan:?string, hadir:int, pertemuan:int}>
     */
    public function forStudent(Student $student, Term $term): array
    {
        $members = ExtracurricularMember::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $term->academic_year_id)
            ->with('extracurricular.pembina')
            ->get();

        if ($members->isEmpty()) {
            return [];
        }

        $assessments = ExtracurricularAssessment::where('term_id', $term->id)
            ->whereIn('extracurricular_member_id', $members->pluck('id'))
            ->get()->keyBy('extracurricular_member_id');

        $attendance = ExtracurricularAttendance::query()
            ->whereIn('extracurricular_member_id', $members->pluck('id'))
            ->whereHas('meeting', fn ($q) => $q->whereDate('date', '>=', $term->starts_on)->whereDate('date', '<=', $term->ends_on))
            ->get()
            ->groupBy('extracurricular_member_id');

        return $members
            // A member who left without ever being assessed has nothing to report.
            ->filter(fn ($m) => $m->status === 'active' || $assessments->has($m->id))
            ->map(function (ExtracurricularMember $m) use ($assessments, $attendance) {
                $assessment = $assessments->get($m->id);
                $marks = $attendance->get($m->id, collect());

                return [
                    'name' => $m->extracurricular?->name ?? '-',
                    'pembina' => $m->extracurricular?->pembina?->name,
                    'predikat' => $assessment?->predikat,
                    'predikat_label' => $assessment ? ExtracurricularAssessment::PREDIKAT[$assessment->predikat] : null,
                    'keterangan' => $assessment?->keterangan,
                    'hadir' => $marks->where('status', 'hadir')->count(),
                    'pertemuan' => $marks->count(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }
}
