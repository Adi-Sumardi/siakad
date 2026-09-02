<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Achievement;
use App\Models\Bill;
use App\Models\SchoolUnit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardChartController extends Controller
{
    /**
     * Get billing and receivables chart data per school unit with custom date range.
     */
    public function billingChart(Request $request): JsonResponse
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $academicYearInput = $request->input('academic_year');

        $activeYear = null;
        if (! empty($academicYearInput)) {
            $activeYear = AcademicYear::where('year', $academicYearInput)->first()
                ?? AcademicYear::where('ulid', $academicYearInput)->first();
        }

        if (! $activeYear && empty($startDate) && empty($endDate)) {
            $activeYear = AcademicYear::where('is_active', true)->first()
                ?? AcademicYear::latest('starts_on')->first();
        }

        // A unit-scoped admin/guru's bills were already filtered by
        // visibleTo() below, but the unit LIST itself was not - every other
        // unit's code/label/jenjang still showed up in the response with
        // zeroed-out totals. Not a financial leak (the totals were
        // genuinely empty), but still more of the school's structure than
        // a single-unit admin should see.
        $units = SchoolUnit::active()->ordered()
            ->when($request->user()?->isUnitScoped(), fn ($q) => $q->where('id', $request->user()->school_unit_id))
            ->get();

        $billsQuery = Bill::query()
            ->visibleTo($request->user())
            ->whereNotIn('status', ['cancelled'])
            ->with(['student:id,school_unit_id']);

        if (! empty($startDate)) {
            $billsQuery->whereDate('issued_at', '>=', Carbon::parse($startDate)->toDateString());
        }
        if (! empty($endDate)) {
            $billsQuery->whereDate('issued_at', '<=', Carbon::parse($endDate)->toDateString());
        }

        if ($activeYear && empty($startDate) && empty($endDate)) {
            $billsQuery->where('academic_year_id', $activeYear->id);
        }

        $allBills = $billsQuery->get([
            'id', 'student_id', 'total_amount', 'paid_amount', 'remaining_amount', 'status', 'due_date',
        ]);

        $unitData = [];
        $grandTotalBilled = 0;
        $grandTotalPaid = 0;
        $grandTotalOutstanding = 0;

        foreach ($units as $unit) {
            $unitBills = $allBills->filter(fn ($b) => $b->student && $b->student->school_unit_id === $unit->id);
            $billed = (float) $unitBills->sum('total_amount');
            $paid = (float) $unitBills->sum('paid_amount');
            $outstanding = (float) $unitBills->sum('remaining_amount');

            $grandTotalBilled += $billed;
            $grandTotalPaid += $paid;
            $grandTotalOutstanding += $outstanding;

            $paidCount = $unitBills->where('status', 'paid')->count();
            $partialCount = $unitBills->where('status', 'partial')->count();
            $unpaidCount = $unitBills->where('status', 'unpaid')->count();
            $overdueCount = $unitBills->filter(fn ($b) => $b->remaining_amount > 0 && $b->due_date && Carbon::parse($b->due_date)->isPast())->count();

            $collectionRate = $billed > 0 ? round(($paid / $billed) * 100, 1) : 0;

            $unitData[] = [
                'unit_id' => $unit->id,
                'unit_code' => $unit->code,
                'unit_label' => $unit->label,
                'jenjang' => strtoupper($unit->jenjang_group),
                'total_billed' => $billed,
                'total_paid' => $paid,
                'total_outstanding' => $outstanding,
                'collection_rate' => $collectionRate,
                'bill_count' => $unitBills->count(),
                'paid_count' => $paidCount,
                'partial_count' => $partialCount,
                'unpaid_count' => $unpaidCount,
                'overdue_count' => $overdueCount,
            ];
        }

        return response()->json([
            'filter' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'academic_year' => $activeYear?->year,
            ],
            'summary' => [
                'total_billed' => $grandTotalBilled,
                'total_paid' => $grandTotalPaid,
                'total_outstanding' => $grandTotalOutstanding,
                'collection_rate' => $grandTotalBilled > 0 ? round(($grandTotalPaid / $grandTotalBilled) * 100, 1) : 0,
                'total_bills' => $allBills->count(),
            ],
            'units' => $unitData,
        ]);
    }

    /**
     * Get achievements chart data per school unit with student vs teacher filter.
     */
    public function achievementsChart(Request $request): JsonResponse
    {
        $achieverType = $request->input('achiever_type', 'all'); // 'all', 'siswa', 'guru'
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $kategori = $request->input('kategori');

        $units = SchoolUnit::active()->ordered()
            ->when($request->user()?->isUnitScoped(), fn ($q) => $q->where('id', $request->user()->school_unit_id))
            ->get();

        $query = Achievement::query()
            ->visibleTo($request->user())
            ->where('status', 'verified')
            ->with(['student.schoolUnit', 'teacher.schoolUnit', 'schoolUnit']);

        if ($achieverType === 'siswa') {
            $query->where(fn ($q) => $q->where('achiever_type', 'siswa')->orWhereNull('achiever_type'));
        } elseif ($achieverType === 'guru') {
            $query->where('achiever_type', 'guru');
        }

        if (! empty($startDate)) {
            $query->whereDate('tanggal_event', '>=', Carbon::parse($startDate)->toDateString());
        }
        if (! empty($endDate)) {
            $query->whereDate('tanggal_event', '<=', Carbon::parse($endDate)->toDateString());
        }
        if (! empty($kategori)) {
            $query->where('kategori', $kategori);
        }

        $allAchievements = $query->get();

        $unitData = [];
        $totalAll = 0;
        $levelCounts = [
            'Internasional' => 0,
            'Nasional' => 0,
            'Provinsi' => 0,
            'Kabupaten/Kota' => 0,
            'Kecamatan' => 0,
            'Sekolah' => 0,
        ];
        $juaraCounts = [
            'Juara 1' => 0,
            'Juara 2' => 0,
            'Juara 3' => 0,
            'Lainnya' => 0,
        ];

        foreach ($units as $unit) {
            $unitAchievements = $allAchievements->filter(function ($a) use ($unit) {
                if ($a->school_unit_id === $unit->id) return true;
                if ($a->student && $a->student->school_unit_id === $unit->id) return true;
                if ($a->teacher && $a->teacher->school_unit_id === $unit->id) return true;
                return false;
            });

            $count = $unitAchievements->count();
            $totalAll += $count;

            $byTingkat = [];
            foreach (['Internasional', 'Nasional', 'Provinsi', 'Kabupaten/Kota', 'Kecamatan', 'Sekolah'] as $lvl) {
                $c = $unitAchievements->where('tingkat', $lvl)->count();
                $byTingkat[$lvl] = $c;
                $levelCounts[$lvl] += $c;
            }

            $siswaCount = $unitAchievements->filter(fn ($a) => $a->achiever_type === 'siswa' || empty($a->achiever_type))->count();
            $guruCount = $unitAchievements->where('achiever_type', 'guru')->count();

            $unitData[] = [
                'unit_id' => $unit->id,
                'unit_code' => $unit->code,
                'unit_label' => $unit->label,
                'jenjang' => strtoupper($unit->jenjang_group),
                'total_achievements' => $count,
                'siswa_count' => $siswaCount,
                'guru_count' => $guruCount,
                'by_tingkat' => $byTingkat,
                'recent' => $unitAchievements->take(3)->map(fn ($a) => [
                    'nama_prestasi' => $a->nama_prestasi,
                    'tingkat' => $a->tingkat,
                    'juara' => $a->juara,
                    'achiever' => $a->achiever_type === 'guru' ? ($a->teacher?->name ?? 'Guru') : ($a->student?->nama_lengkap ?? 'Siswa'),
                    'achiever_type' => $a->achiever_type ?? 'siswa',
                    'tanggal' => $a->tanggal_event?->format('Y-m-d'),
                ]),
            ];
        }

        return response()->json([
            'filter' => [
                'achiever_type' => $achieverType,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'kategori' => $kategori,
            ],
            'summary' => [
                'total_achievements' => $totalAll,
                'total_siswa' => $allAchievements->filter(fn ($a) => $a->achiever_type === 'siswa' || empty($a->achiever_type))->count(),
                'total_guru' => $allAchievements->where('achiever_type', 'guru')->count(),
                'by_level' => $levelCounts,
            ],
            'units' => $unitData,
        ]);
    }
}
