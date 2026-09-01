<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Term;
use App\Services\Academic\GradeService;
use App\Services\Academic\RaporPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GradeController extends Controller
{
    public function index(Request $request, string $studentUlid, GradeService $service): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $studentUlid)->firstOrFail();
        $term = $this->resolveTerm($request);

        if (! $term) {
            return response()->json(['term' => null, 'terms' => $this->availableTerms(), 'subjects' => []]);
        }

        return response()->json([
            'term' => $term->label(),
            'term_ulid' => $term->ulid,
            'terms' => $this->availableTerms(),
            'subjects' => $service->summaryForRapor($student, $term),
        ]);
    }

    public function rapor(Request $request, string $studentUlid, RaporPdfService $pdf, GradeService $service): Response
    {
        $student = Student::visibleTo($request->user())->where('ulid', $studentUlid)->firstOrFail();
        $term = $this->resolveTerm($request);

        abort_if(! $term, 422, 'Belum ada semester yang bisa dipilih.');

        return $pdf->render($student, $term, $service)->stream($pdf->filename($student, $term));
    }

    /** Defaults to the active term when none is picked - past semesters stay reachable by ulid instead of vanishing the moment a new one activates. */
    private function resolveTerm(Request $request): ?Term
    {
        $termUlid = $request->string('term_ulid')->value();

        if ($termUlid) {
            return Term::where('ulid', $termUlid)->first();
        }

        return Term::current();
    }

    private function availableTerms(): array
    {
        return Term::orderByDesc('starts_on')->get()->map(fn (Term $t) => [
            'ulid' => $t->ulid, 'label' => $t->label(), 'is_active' => $t->is_active,
        ])->all();
    }
}
