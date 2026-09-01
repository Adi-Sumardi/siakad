<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Term;
use App\Services\Academic\GradeService;
use App\Services\Academic\RaporPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GradeController extends Controller
{
    /** Oversight only - reading, never editing. Editing stays with the assigned teacher via GuruGradeController. */
    public function index(Request $request): JsonResponse
    {
        $grades = Grade::query()
            ->visibleTo($request->user())
            ->with(['student', 'subject', 'classroom', 'term'])
            ->when($request->string('classroom')->value(), fn ($q, $ulid) => $q->whereHas('classroom', fn ($c) => $c->where('ulid', $ulid)))
            ->when($request->string('subject')->value(), fn ($q, $ulid) => $q->whereHas('subject', fn ($s) => $s->where('ulid', $ulid)))
            ->when($request->string('term')->value(), fn ($q, $ulid) => $q->whereHas('term', fn ($t) => $t->where('ulid', $ulid)))
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get();

        return response()->json([
            'grades' => $grades->map(fn (Grade $g) => [
                'ulid' => $g->ulid,
                'student' => ['ulid' => $g->student->ulid, 'nama_lengkap' => $g->student->nama_lengkap],
                'subject' => $g->subject->name,
                'classroom' => $g->classroom->name,
                'term' => $g->term->label(),
                'category' => $g->category,
                'score' => (float) $g->score,
                'updated_at' => $g->updated_at,
            ]),
        ]);
    }

    public function rapor(Request $request, string $studentUlid, RaporPdfService $pdf, GradeService $service): Response
    {
        $student = Student::visibleTo($request->user())->where('ulid', $studentUlid)->firstOrFail();

        $termUlid = $request->string('term_ulid')->value();
        $term = $termUlid ? Term::where('ulid', $termUlid)->first() : Term::current();

        abort_if(! $term, 422, 'Belum ada semester yang bisa dipilih.');

        return $pdf->render($student, $term, $service)->stream($pdf->filename($student, $term));
    }
}
