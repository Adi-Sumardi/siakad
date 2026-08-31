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
        $term = Term::current();

        if (! $term) {
            return response()->json(['term' => null, 'subjects' => []]);
        }

        return response()->json([
            'term' => $term->label(),
            'subjects' => $service->summaryForRapor($student, $term),
        ]);
    }

    public function rapor(Request $request, string $studentUlid, RaporPdfService $pdf, GradeService $service): Response
    {
        $student = Student::visibleTo($request->user())->where('ulid', $studentUlid)->firstOrFail();
        $term = Term::current();

        abort_if(! $term, 422, 'Belum ada semester aktif.');

        return $pdf->render($student, $term, $service)->stream($pdf->filename($student, $term));
    }
}
