<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DateRangeRequest;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Services\Reporting\CollectionReconExcel;
use App\Services\Reporting\CollectionReconPdf;
use App\Services\Reporting\CollectionReconService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    /**
     * Who owes what, grouped the way a bendahara chases it: by class, because
     * that is who a wali kelas can actually call.
     */
    public function receivables(Request $request): JsonResponse
    {
        $bills = Bill::query()
            ->visibleTo($request->user())
            ->open()
            ->with(['student.enrollments.classroom', 'student.schoolUnit', 'feeType'])
            ->get();

        $byClass = $bills
            ->groupBy(fn (Bill $bill) => $bill->student->currentEnrollment()?->classroom?->name ?? 'Belum ada kelas')
            ->map(fn ($group, $kelas) => [
                'kelas' => $kelas,
                'students' => $group->pluck('student_id')->unique()->count(),
                'bills' => $group->count(),
                'outstanding' => round((float) $group->sum('remaining_amount'), 2),
                'overdue' => round((float) $group->where('status', 'overdue')->sum('remaining_amount'), 2),
            ])
            ->sortByDesc('outstanding')
            ->values();

        return response()->json([
            'summary' => [
                'outstanding' => round((float) $bills->sum('remaining_amount'), 2),
                'bills' => $bills->count(),
                'families' => $bills->pluck('student_id')->unique()->count(),
                'overdue_bills' => $bills->where('status', 'overdue')->count(),
            ],
            'by_class' => $byClass,
            'by_fee_type' => $bills->groupBy(fn (Bill $bill) => $bill->feeType->name)
                ->map(fn ($group, $name) => [
                    'fee_type' => $name,
                    'bills' => $group->count(),
                    'outstanding' => round((float) $group->sum('remaining_amount'), 2),
                ])->values(),
        ]);
    }

    /** What actually came in, over a window an admin picks. */
    public function collections(DateRangeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now()->endOfDay();

        $payments = Payment::query()
            ->visibleTo($request->user())
            ->where('status', 'completed')
            ->whereBetween('paid_at', [$from, $to])
            ->with('bills.feeType')
            ->get();

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total' => round((float) $payments->sum('amount'), 2),
            'count' => $payments->count(),
            'by_method' => $payments->groupBy('method')
                ->map(fn ($group, $method) => [
                    'method' => $method ?? 'lainnya',
                    'count' => $group->count(),
                    'total' => round((float) $group->sum('amount'), 2),
                ])->values(),
            // Summed over allocations, not over payments: one payment can cover
            // several fee types and attributing it whole to one would overstate
            // that type and hide the others.
            'by_fee_type' => $payments->flatMap(fn (Payment $p) => $p->bills->map(fn ($bill) => [
                'fee_type' => $bill->feeType->name,
                'amount' => (float) $bill->pivot->amount,
            ]))->groupBy('fee_type')->map(fn ($group, $name) => [
                'fee_type' => $name,
                'total' => round((float) collect($group)->sum('amount'), 2),
            ])->values(),
        ]);
    }

    /**
     * What the recon dialog can narrow by: the fee types and units this
     * admin can see. A unit admin's own unit only.
     */
    public function reconOptions(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'fee_types' => FeeType::query()->orderBy('sort_order')->orderBy('name')->get(['code', 'name']),
            'units' => SchoolUnit::query()->ordered()
                ->when($user->isUnitScoped(), fn ($q) => $q->whereKey($user->school_unit_id ?? 0))
                ->get(['code', 'label']),
        ]);
    }

    /** Recon PDF - per-unit totals, then each unit's paid allocations (2026-10-07). */
    public function reconPdf(Request $request, CollectionReconService $recon, CollectionReconPdf $pdf): Response
    {
        $report = $recon->build($this->reconFilters($request), $request->user());

        return new Response($pdf->render($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$recon->filename($report, 'pdf').'"',
        ]);
    }

    /** Recon Excel - Ringkasan, a sheet per unit, and Semua Transaksi. */
    public function reconExcel(Request $request, CollectionReconService $recon, CollectionReconExcel $excel): Response
    {
        $report = $recon->build($this->reconFilters($request), $request->user());

        return new Response($excel->render($report), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$recon->filename($report, 'xlsx').'"',
        ]);
    }

    /** By PAYMENT date. */
    private function reconFilters(Request $request): array
    {
        return $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'bank' => 'nullable|in:all,muamalat,bsi',
            'fee_type' => 'nullable|string|max:40',
            'unit' => 'nullable|string|max:40',
        ], [
            'from.required' => 'Tanggal bayar awal wajib diisi.',
            'to.required' => 'Tanggal bayar akhir wajib diisi.',
            'to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ]);
    }
}
