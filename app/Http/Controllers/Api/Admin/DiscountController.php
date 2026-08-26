<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\DiscountScheme;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\StudentDiscount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    /**
     * List all discount schemes.
     */
    public function schemes(Request $request): JsonResponse
    {
        $schemes = DiscountScheme::query()
            ->with(['feeType', 'schoolUnit'])
            // Same pattern as fee rates and point rules: a per-unit admin
            // sees their own unit's schemes plus school-wide ones, never
            // another unit's. This read had no scope at all before - every
            // admin_unit saw every other unit's discount policy.
            ->when($request->user()->isUnitScoped(), fn ($q) => $q->where(fn ($sq) => $sq->whereNull('school_unit_id')->orWhere('school_unit_id', $request->user()->school_unit_id)))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return response()->json([
            'schemes' => $schemes->map(fn (DiscountScheme $s) => [
                'ulid' => $s->ulid,
                'code' => $s->code,
                'name' => $s->name,
                'type' => $s->type,
                'value' => (float) $s->value,
                'fee_type' => $s->feeType ? ['ulid' => $s->feeType->ulid, 'code' => $s->feeType->code, 'name' => $s->feeType->name] : null,
                'school_unit' => $s->schoolUnit ? ['ulid' => $s->schoolUnit->ulid, 'code' => $s->schoolUnit->code, 'label' => $s->schoolUnit->label] : null,
                'is_active' => $s->is_active,
                'notes' => $s->notes,
                'student_count' => $s->studentDiscounts()->count(),
            ]),
        ]);
    }

    /**
     * Create a new discount scheme.
     */
    public function storeScheme(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:32|alpha_dash|unique:discount_schemes,code',
            'name' => 'required|string|max:120',
            'type' => 'required|in:percent,nominal',
            'value' => 'required|numeric|min:0',
            'fee_type_ulid' => 'nullable|exists:fee_types,ulid',
            'school_unit_ulid' => 'nullable|exists:school_units,ulid',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:500',
        ]);

        $feeType = ! empty($validated['fee_type_ulid']) ? FeeType::where('ulid', $validated['fee_type_ulid'])->first() : null;
        $unit = ! empty($validated['school_unit_ulid']) ? SchoolUnit::where('ulid', $validated['school_unit_ulid'])->first() : null;

        $scheme = DiscountScheme::create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'value' => $validated['value'],
            'fee_type_id' => $feeType?->id,
            'school_unit_id' => $unit?->id,
            'is_active' => $validated['is_active'] ?? true,
            'notes' => $validated['notes'] ?? null,
        ]);

        ActivityLog::record($request->user(), 'discount_scheme.created', $scheme, ['code' => $scheme->code]);

        return response()->json(['scheme' => $scheme], 201);
    }

    /**
     * Update a discount scheme.
     */
    public function updateScheme(Request $request, DiscountScheme $discountScheme): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:120',
            'type' => 'sometimes|in:percent,nominal',
            'value' => 'sometimes|numeric|min:0',
            'fee_type_ulid' => 'nullable|exists:fee_types,ulid',
            'school_unit_ulid' => 'nullable|exists:school_units,ulid',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:500',
        ]);

        if (array_key_exists('fee_type_ulid', $validated)) {
            $feeType = ! empty($validated['fee_type_ulid']) ? FeeType::where('ulid', $validated['fee_type_ulid'])->first() : null;
            $discountScheme->fee_type_id = $feeType?->id;
        }

        if (array_key_exists('school_unit_ulid', $validated)) {
            $unit = ! empty($validated['school_unit_ulid']) ? SchoolUnit::where('ulid', $validated['school_unit_ulid'])->first() : null;
            $discountScheme->school_unit_id = $unit?->id;
        }

        $discountScheme->fill(collect($validated)->except(['fee_type_ulid', 'school_unit_ulid'])->all());
        $discountScheme->save();

        ActivityLog::record($request->user(), 'discount_scheme.updated', $discountScheme, $validated);

        return response()->json(['scheme' => $discountScheme]);
    }

    /**
     * Delete a discount scheme.
     */
    public function destroyScheme(Request $request, DiscountScheme $discountScheme): JsonResponse
    {
        ActivityLog::record($request->user(), 'discount_scheme.deleted', $discountScheme, ['code' => $discountScheme->code]);
        $discountScheme->delete();

        return response()->json(['message' => 'Skema diskon berhasil dihapus.']);
    }

    /**
     * List all student discounts.
     */
    public function studentDiscounts(Request $request): JsonResponse
    {
        $discounts = StudentDiscount::query()
            ->with(['student.schoolUnit', 'scheme.feeType', 'academicYear'])
            // Which specific student has which scholarship, and why - real
            // per-family sensitive information (the reason often names a
            // hardship or circumstance), scoped to the caller's own unit the
            // same way Student::visibleTo() scopes everywhere else a student
            // is reached from an admin endpoint.
            ->when($request->user()->isUnitScoped(), fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('school_unit_id', $request->user()->school_unit_id)))
            ->when($request->string('student')->value(), function ($q, $search) {
                $q->whereHas('student', fn ($sq) => $sq->where('nama_lengkap', 'like', "%{$search}%")->orWhere('nis', 'like', "%{$search}%"));
            })
            ->when(! $request->user()->isUnitScoped() && $request->string('unit')->value(), function ($q, $unitCode) {
                $q->whereHas('student.schoolUnit', fn ($uq) => $uq->where('code', $unitCode));
            })
            ->latest()
            ->get();

        return response()->json([
            'student_discounts' => $discounts->map(fn (StudentDiscount $sd) => [
                'ulid' => $sd->ulid,
                'student' => [
                    'ulid' => $sd->student->ulid,
                    'nama_lengkap' => $sd->student->nama_lengkap,
                    'nis' => $sd->student->nis,
                    'unit' => $sd->student->schoolUnit?->label,
                ],
                'scheme' => [
                    'ulid' => $sd->scheme->ulid,
                    'code' => $sd->scheme->code,
                    'name' => $sd->scheme->name,
                    'type' => $sd->scheme->type,
                    'value' => (float) $sd->scheme->value,
                    'fee_type' => $sd->scheme->feeType?->name ?? 'Semua Tagihan',
                ],
                'academic_year' => $sd->academicYear?->year,
                'effective_from' => $sd->effective_from?->toDateString(),
                'effective_to' => $sd->effective_to?->toDateString(),
                'reason' => $sd->reason,
            ]),
        ]);
    }

    /**
     * Assign a discount scheme to a student.
     */
    public function assignStudentDiscount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_ulid' => 'required|exists:students,ulid',
            'discount_scheme_ulid' => 'required|exists:discount_schemes,ulid',
            'academic_year_ulid' => 'required|exists:academic_years,ulid',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'reason' => 'nullable|string|max:500',
        ]);

        $student = Student::where('ulid', $validated['student_ulid'])->firstOrFail();
        $scheme = DiscountScheme::where('ulid', $validated['discount_scheme_ulid'])->firstOrFail();
        $year = AcademicYear::where('ulid', $validated['academic_year_ulid'])->firstOrFail();

        $exists = StudentDiscount::where('student_id', $student->id)
            ->where('discount_scheme_id', $scheme->id)
            ->where('academic_year_id', $year->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Diskon ini sudah diterapkan untuk siswa dan tahun ajaran yang dipilih.'], 422);
        }

        $sd = StudentDiscount::create([
            'student_id' => $student->id,
            'discount_scheme_id' => $scheme->id,
            'academic_year_id' => $year->id,
            'effective_from' => $validated['effective_from'],
            'effective_to' => $validated['effective_to'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        ActivityLog::record($request->user(), 'student_discount.assigned', $sd, [
            'student' => $student->nama_lengkap,
            'scheme' => $scheme->code,
        ]);

        return response()->json(['student_discount' => $sd->load(['student', 'scheme'])], 201);
    }

    /**
     * Revoke / delete a student discount.
     */
    public function revokeStudentDiscount(Request $request, StudentDiscount $studentDiscount): JsonResponse
    {
        ActivityLog::record($request->user(), 'student_discount.revoked', $studentDiscount, []);
        $studentDiscount->delete();

        return response()->json(['message' => 'Diskon siswa berhasil dihapus.']);
    }
}
