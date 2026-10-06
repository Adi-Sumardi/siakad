<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Student;
use App\Models\StudentDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Student documents - akta, KK, ijazah, foto, ... (audit 6 Okt 2026 #7). The
 * table and model existed since the first schema with nothing able to write
 * or read them. Both lanes go through Student::visibleTo(): staff of the
 * child's unit and the child's own guardian. A guardian's upload waits for
 * TU verification; staff uploads are verified on arrival. Out-of-scope is
 * 404, never 403 (R3).
 */
class StudentDocumentController extends Controller
{
    public const TYPES = ['akta', 'kk', 'ijazah', 'foto', 'rapor_sebelumnya', 'kip', 'lainnya'];

    public const TYPE_LABELS = [
        'akta' => 'Akta Kelahiran',
        'kk' => 'Kartu Keluarga',
        'ijazah' => 'Ijazah',
        'foto' => 'Pas Foto',
        'rapor_sebelumnya' => 'Rapor Sekolah Sebelumnya',
        'kip' => 'KIP',
        'lainnya' => 'Lainnya',
    ];

    public function index(Request $request, string $ulid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        return response()->json([
            'types' => collect(self::TYPE_LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'documents' => $student->documents()->with('verifiedBy')->latest()->get()->map(fn (StudentDocument $doc) => $this->present($doc)),
        ]);
    }

    public function store(Request $request, string $ulid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        $validated = $request->validate([
            'document_type' => 'required|in:'.implode(',', self::TYPES),
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $file = $request->file('file');
        $byStaff = ! $request->user()->isGuardian();

        $doc = $student->documents()->create([
            'document_type' => $validated['document_type'],
            'file_path' => $file->store('student-documents', 'local'),
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime' => $file->getMimeType(),
            'uploaded_by' => $request->user()->id,
            'verified_at' => $byStaff ? now() : null,
            'verified_by' => $byStaff ? $request->user()->id : null,
        ]);

        ActivityLog::record($request->user(), 'student_document.uploaded', $doc, [
            'student' => $student->nama_lengkap,
            'type' => $doc->document_type,
        ]);

        return response()->json(['document' => $this->present($doc->load('verifiedBy'))], 201);
    }

    public function verify(Request $request, string $ulid): JsonResponse
    {
        $doc = $this->scopedDocument($request, $ulid);

        if (! $doc->verified_at) {
            $doc->forceFill(['verified_at' => now(), 'verified_by' => $request->user()->id])->save();
            ActivityLog::record($request->user(), 'student_document.verified', $doc, ['type' => $doc->document_type]);
        }

        return response()->json(['document' => $this->present($doc->load('verifiedBy'))]);
    }

    /** Staff may remove any; a guardian only their own still-unverified upload. */
    public function destroy(Request $request, string $ulid): JsonResponse
    {
        $doc = $this->scopedDocument($request, $ulid);
        $user = $request->user();

        if ($user->isGuardian() && ($doc->verified_at || $doc->uploaded_by !== $user->id)) {
            return response()->json(['message' => 'Dokumen yang sudah diverifikasi sekolah hanya bisa dihapus oleh TU.'], 422);
        }

        Storage::disk('local')->delete($doc->file_path);
        ActivityLog::record($user, 'student_document.deleted', $doc, ['type' => $doc->document_type, 'file' => $doc->file_name]);
        $doc->delete();

        return response()->json(['status' => 'ok']);
    }

    public function file(Request $request, string $ulid): StreamedResponse
    {
        $doc = $this->scopedDocument($request, $ulid);

        abort_if(! Storage::disk('local')->exists($doc->file_path), 404);

        return Storage::disk('local')->response($doc->file_path, $doc->file_name);
    }

    private function scopedDocument(Request $request, string $ulid): StudentDocument
    {
        return StudentDocument::where('ulid', $ulid)
            ->whereIn('student_id', Student::visibleTo($request->user())->select('id'))
            ->firstOrFail();
    }

    private function present(StudentDocument $doc): array
    {
        return [
            'ulid' => $doc->ulid,
            'document_type' => $doc->document_type,
            'type_label' => self::TYPE_LABELS[$doc->document_type] ?? $doc->document_type,
            'file_name' => $doc->file_name,
            'file_size' => $doc->file_size,
            'verified' => (bool) $doc->verified_at,
            'verified_at' => $doc->verified_at,
            'verified_by' => $doc->verifiedBy?->name,
            'uploaded_by_me' => $doc->uploaded_by === request()->user()?->id,
            'created_at' => $doc->created_at,
        ];
    }
}
