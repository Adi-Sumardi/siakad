<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Http\Resources\PointRecordResource;
use App\Models\ActivityLog;
use App\Models\PointRecord;
use App\Models\PointRule;
use App\Models\Student;
use App\Models\Term;
use App\Services\Points\PointLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class PointController extends Controller
{
    /**
     * One student's ledger this term - what a teacher needs before deciding
     * whether something they, or a colleague, recorded should be revoked.
     * Same shape as the guardian's own view; Student::visibleTo() already
     * treats a teacher as scoped to their whole unit.
     */
    public function studentLedger(Request $request, string $ulid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();
        $term = Term::current();

        if (! $term) {
            return response()->json(['balance' => 0, 'term' => null, 'records' => []]);
        }

        $records = $student->pointRecords()
            ->where('term_id', $term->id)
            ->with(['pointRule', 'recordedBy'])
            ->orderByDesc('occurred_on')
            ->get();

        return response()->json([
            'student' => ['ulid' => $student->ulid, 'nama_lengkap' => $student->nama_lengkap],
            'balance' => app(PointLedger::class)->balance($student, $term),
            'term' => $term->label(),
            'records' => PointRecordResource::collection($records),
        ]);
    }

    /** The catalogue this teacher's unit may use: its own rules plus the school-wide ones. */
    public function rules(Request $request): JsonResponse
    {
        $rules = PointRule::query()
            ->active()
            ->forUnit($request->user()->school_unit_id)
            ->orderBy('type')->orderBy('sort_order')
            ->get();

        return response()->json([
            'rules' => $rules->map(fn (PointRule $r) => [
                'ulid' => $r->ulid,
                'code' => $r->code,
                'name' => $r->name,
                'type' => $r->type,
                'category' => $r->category,
                'points' => $r->signedPoints(),
                'requires_evidence' => $r->requires_evidence,
            ]),
        ]);
    }

    public function store(Request $request, PointLedger $ledger): JsonResponse
    {
        $validated = $this->validateSingle($request);

        [$student, $rule, $term] = $this->resolve($request, $validated);

        try {
            $record = $ledger->record(
                $student, $term, $rule, $request->user(),
                Carbon::parse($validated['occurred_on']), $validated['description'],
                $this->storeEvidence($request),
                $request->file('evidence')?->getClientOriginalName(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLog::record($request->user(), 'point.recorded', $record, [
            'student' => $student->nama_lengkap, 'rule' => $rule->code, 'points' => $record->points,
        ]);

        return response()->json(['record' => new PointRecordResource($record->load('pointRule'))], 201);
    }

    /**
     * One rule, many students - a whole line late to assembly recorded in one
     * click instead of thirty identical ones.
     */
    public function storeBulk(Request $request, PointLedger $ledger): JsonResponse
    {
        $validated = $request->validate([
            'student_ulids' => 'required|array|min:1|max:200',
            'student_ulids.*' => 'required|string',
            'point_rule_ulid' => 'required|string',
            'occurred_on' => 'required|date|before_or_equal:today',
            'description' => 'required|string|max:1000',
        ]);

        $rule = PointRule::where('ulid', $validated['point_rule_ulid'])
            ->forUnit($request->user()->school_unit_id)->active()->firstOrFail();

        $term = Term::current();
        abort_if(! $term, 422, 'Belum ada semester aktif.');

        $students = Student::visibleTo($request->user())
            ->whereIn('ulid', $validated['student_ulids'])->get();

        if ($students->count() !== count(array_unique($validated['student_ulids']))) {
            return response()->json(['message' => 'Sebagian siswa tidak ditemukan atau bukan wewenang Anda.'], 422);
        }

        if ($rule->requires_evidence) {
            // A bulk action has no single piece of evidence to attach to thirty
            // students at once - a rule that demands proof belongs on the
            // single-entry form, not here.
            return response()->json(['message' => "Aturan '{$rule->name}' mewajibkan bukti dan tidak bisa dicatat massal."], 422);
        }

        $records = $ledger->recordBulk($students, $term, $rule, $request->user(), Carbon::parse($validated['occurred_on']), $validated['description']);

        ActivityLog::record($request->user(), 'point.recorded_bulk', null, [
            'rule' => $rule->code, 'student_count' => $records->count(),
        ]);

        return response()->json(['recorded' => $records->count()], 201);
    }

    /** Excludes the record from every balance from now on; the row and its reasoning stay on file. */
    public function revoke(Request $request, string $ulid, PointLedger $ledger): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        $record = PointRecord::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        try {
            $ledger->revoke($record, $request->user(), $validated['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLog::record($request->user(), 'point.revoked', $record, ['reason' => $validated['reason']]);

        return response()->json(['record' => new PointRecordResource($record->fresh())]);
    }

    private function validateSingle(Request $request): array
    {
        return $request->validate([
            'student_ulid' => 'required|string',
            'point_rule_ulid' => 'required|string',
            'occurred_on' => 'required|date|before_or_equal:today',
            'description' => 'required|string|max:1000',
            'evidence' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);
    }

    /** @return array{0: Student, 1: PointRule, 2: Term} */
    private function resolve(Request $request, array $validated): array
    {
        $student = Student::visibleTo($request->user())->where('ulid', $validated['student_ulid'])->firstOrFail();
        $rule = PointRule::where('ulid', $validated['point_rule_ulid'])
            ->forUnit($request->user()->school_unit_id)->active()->firstOrFail();

        $term = Term::current();
        abort_if(! $term, 422, 'Belum ada semester aktif.');

        return [$student, $rule, $term];
    }

    private function storeEvidence(Request $request): ?string
    {
        return $request->hasFile('evidence')
            ? $request->file('evidence')->store('points/evidence', 'local')
            : null;
    }
}
