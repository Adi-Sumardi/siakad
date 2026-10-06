<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guru\AssignExtracurricularMemberRequest;
use App\Models\Extracurricular;
use App\Models\ExtracurricularAssessment;
use App\Models\ExtracurricularAttendance;
use App\Models\ExtracurricularMeeting;
use App\Models\ExtracurricularMember;
use App\Models\Student;
use App\Models\Term;
use App\Services\Academic\ExtracurricularService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A pembina manages only the activities they themselves supervise - not
 * every activity in their unit the way an admin does. Every query here is
 * scoped by pembina_id, deliberately not Extracurricular::visibleTo(),
 * which is a broader unit-wide scope built for the admin side.
 */
class ExtracurricularController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activities = Extracurricular::where('pembina_id', $request->user()->id)
            ->withCount(['activeMembers as member_count'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'extracurriculars' => $activities->map(fn (Extracurricular $e) => [
                'ulid' => $e->ulid, 'name' => $e->name, 'capacity' => $e->capacity, 'member_count' => $e->member_count,
            ]),
        ]);
    }

    public function roster(Request $request, string $ulid): JsonResponse
    {
        $ekskul = $this->ownActivity($request, $ulid);

        $members = $ekskul->activeMembers()->with('student')->get();

        return response()->json([
            'extracurricular' => ['ulid' => $ekskul->ulid, 'name' => $ekskul->name],
            'members' => $members->map(fn (ExtracurricularMember $m) => [
                'ulid' => $m->ulid,
                'student' => ['ulid' => $m->student->ulid, 'nama_lengkap' => $m->student->nama_lengkap, 'nis' => $m->student->nis],
                'joined_on' => $m->joined_on?->toDateString(),
            ]),
        ]);
    }

    public function assignStudent(AssignExtracurricularMemberRequest $request, string $ulid, ExtracurricularService $service): JsonResponse
    {
        $ekskul = $this->ownActivity($request, $ulid);

        $validated = $request->validated();
        $student = Student::visibleTo($request->user())->where('ulid', $validated['student_ulid'])->firstOrFail();

        try {
            $member = $service->assignStudent($ekskul, $student, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['member' => ['ulid' => $member->ulid]], 201);
    }

    public function removeMember(Request $request, string $ulid, string $memberUlid, ExtracurricularService $service): JsonResponse
    {
        $ekskul = $this->ownActivity($request, $ulid);
        $member = $ekskul->members()->where('ulid', $memberUlid)->firstOrFail();

        try {
            $service->removeStudent($member);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Anggota dikeluarkan dari ekstrakurikuler.']);
    }

    /**
     * Practice-day attendance (audit 6 Okt 2026 #8): the last meetings with
     * per-member marks, plus each member's tally this term.
     */
    public function meetings(Request $request, string $ulid): JsonResponse
    {
        $ekskul = $this->ownActivity($request, $ulid);
        $term = Term::current();

        $meetings = ExtracurricularMeeting::where('extracurricular_id', $ekskul->id)
            ->with('attendances.member')
            ->orderByDesc('date')
            ->limit(30)
            ->get();

        return response()->json([
            'meetings' => $meetings->map(fn (ExtracurricularMeeting $m) => [
                'ulid' => $m->ulid,
                'date' => $m->date->toDateString(),
                'notes' => $m->notes,
                'records' => $m->attendances->mapWithKeys(fn ($a) => [$a->member?->ulid => $a->status])->filter(),
            ]),
            'term_summary' => $term ? $this->termAttendance($ekskul, $term) : [],
        ]);
    }

    /** Records (or re-records) one practice day. Re-saving the same date replaces its marks. */
    public function storeMeeting(Request $request, string $ulid): JsonResponse
    {
        $ekskul = $this->ownActivity($request, $ulid);

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d|before_or_equal:'.Carbon::now('Asia/Jakarta')->toDateString(),
            'notes' => 'nullable|string|max:500',
            'records' => 'required|array|min:1',
            'records.*.member_ulid' => 'required|string',
            'records.*.status' => 'required|in:hadir,sakit,izin,alpa',
        ], ['date.before_or_equal' => 'Tanggal latihan tidak boleh di masa depan.']);

        $members = $ekskul->members()->whereIn('ulid', collect($validated['records'])->pluck('member_ulid'))->get()->keyBy('ulid');

        if ($members->count() !== collect($validated['records'])->pluck('member_ulid')->unique()->count()) {
            return response()->json(['message' => 'Sebagian siswa bukan anggota ekskul ini.'], 422);
        }

        $meeting = DB::transaction(function () use ($ekskul, $validated, $members, $request) {
            $meeting = ExtracurricularMeeting::updateOrCreate(
                ['extracurricular_id' => $ekskul->id, 'date' => $validated['date']],
                ['notes' => $validated['notes'] ?? null, 'recorded_by' => $request->user()->id],
            );

            foreach ($validated['records'] as $record) {
                ExtracurricularAttendance::updateOrCreate(
                    ['extracurricular_meeting_id' => $meeting->id, 'extracurricular_member_id' => $members[$record['member_ulid']]->id],
                    ['status' => $record['status']],
                );
            }

            return $meeting;
        });

        return response()->json(['meeting' => ['ulid' => $meeting->ulid, 'date' => $meeting->date->toDateString()]], 201);
    }

    /** This term's predikat per active member. */
    public function assessments(Request $request, string $ulid): JsonResponse
    {
        $ekskul = $this->ownActivity($request, $ulid);
        $term = Term::current();

        $existing = $term
            ? ExtracurricularAssessment::where('term_id', $term->id)
                ->whereIn('extracurricular_member_id', $ekskul->members()->select('id'))
                ->get()->keyBy('extracurricular_member_id')
            : collect();

        return response()->json([
            'term' => $term?->label(),
            'predikat_options' => collect(ExtracurricularAssessment::PREDIKAT)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'members' => $ekskul->activeMembers()->with('student')->get()->map(fn (ExtracurricularMember $m) => [
                'member_ulid' => $m->ulid,
                'nama_lengkap' => $m->student?->nama_lengkap,
                'nis' => $m->student?->nis,
                'predikat' => $existing->get($m->id)?->predikat,
                'keterangan' => $existing->get($m->id)?->keterangan,
            ])->sortBy('nama_lengkap')->values(),
        ]);
    }

    public function storeAssessments(Request $request, string $ulid): JsonResponse
    {
        $ekskul = $this->ownActivity($request, $ulid);
        $term = Term::current();

        if (! $term) {
            return response()->json(['message' => 'Tidak ada semester aktif.'], 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.member_ulid' => 'required|string',
            'items.*.predikat' => 'required|in:A,B,C,D',
            'items.*.keterangan' => 'nullable|string|max:500',
        ]);

        $members = $ekskul->members()->whereIn('ulid', collect($validated['items'])->pluck('member_ulid'))->get()->keyBy('ulid');

        if ($members->count() !== collect($validated['items'])->pluck('member_ulid')->unique()->count()) {
            return response()->json(['message' => 'Sebagian siswa bukan anggota ekskul ini.'], 422);
        }

        DB::transaction(function () use ($validated, $members, $term, $request) {
            foreach ($validated['items'] as $item) {
                ExtracurricularAssessment::updateOrCreate(
                    ['extracurricular_member_id' => $members[$item['member_ulid']]->id, 'term_id' => $term->id],
                    ['predikat' => $item['predikat'], 'keterangan' => $item['keterangan'] ?? null, 'assessed_by' => $request->user()->id],
                );
            }
        });

        return response()->json(['saved' => count($validated['items'])]);
    }

    /** @return array<string, array{hadir:int, sakit:int, izin:int, alpa:int}> keyed by member ULID */
    private function termAttendance(Extracurricular $ekskul, Term $term): array
    {
        return ExtracurricularAttendance::query()
            ->whereHas('meeting', fn ($q) => $q->where('extracurricular_id', $ekskul->id)
                ->whereDate('date', '>=', $term->starts_on)
                ->whereDate('date', '<=', $term->ends_on))
            ->with('member')
            ->get()
            ->groupBy(fn ($a) => $a->member?->ulid)
            ->map(fn ($group) => [
                'hadir' => $group->where('status', 'hadir')->count(),
                'sakit' => $group->where('status', 'sakit')->count(),
                'izin' => $group->where('status', 'izin')->count(),
                'alpa' => $group->where('status', 'alpa')->count(),
            ])
            ->all();
    }

    private function ownActivity(Request $request, string $ulid): Extracurricular
    {
        return Extracurricular::where('pembina_id', $request->user()->id)->where('ulid', $ulid)->firstOrFail();
    }
}
