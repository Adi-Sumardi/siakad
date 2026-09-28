<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wali\CheckoutRequest;
use App\Http\Resources\BillResource;
use App\Http\Resources\PaymentResource;
use App\Models\Bill;
use App\Models\Payment;
use App\Services\Billing\BillPdfService;
use App\Services\Billing\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class BillController extends Controller
{
    /**
     * Every child's bills in one list.
     */
    public function index(Request $request): JsonResponse
    {
        $bills = Bill::query()
            ->visibleTo($request->user())
            ->with(['student', 'feeType'])
            ->when($request->string('status')->value() === 'open', fn ($q) => $q->open())
            ->when($request->string('status')->value() === 'paid', fn ($q) => $q->where('status', 'paid'))
            ->when($request->string('student')->value(), fn ($q, $ulid) => $q->whereHas('student', fn ($s) => $s->where('ulid', $ulid)))
            ->orderByRaw("CASE WHEN status IN ('overdue','partial','unpaid') THEN 0 ELSE 1 END")
            ->orderBy('due_date')
            ->get();

        return response()->json([
            'bills' => BillResource::collection($bills),
            'summary' => [
                'outstanding' => (float) $bills->whereIn('status', Bill::OPEN_STATUSES)->sum('remaining_amount'),
                'open_count' => $bills->whereIn('status', Bill::OPEN_STATUSES)->count(),
                'overdue_count' => $bills->where('status', 'overdue')->count(),
            ],
        ]);
    }

    public function show(Request $request, string $ulid): JsonResponse
    {
        $bill = Bill::query()
            ->visibleTo($request->user())
            // student.schoolUnit + academicYear feed the detail screen's
            // header (audit T40-b) - without them the resource omits the
            // fields and the UI used to print hardcoded fallbacks.
            ->with(['student.schoolUnit', 'feeType', 'lines', 'academicYear'])
            ->where('ulid', $ulid)
            ->firstOrFail();

        return response()->json([
            'bill' => new BillResource($bill),
            'payments' => PaymentResource::collection(
                $bill->allocations()->with('payment')->get()->pluck('payment')->filter()->values()
            ),
        ]);
    }

    /**
     * PDF for this bill.
     */
    public function pdf(Request $request, string $ulid, BillPdfService $pdf): Response
    {
        $bill = Bill::query()
            ->visibleTo($request->user())
            ->where('ulid', $ulid)
            ->firstOrFail();

        return $pdf->render($bill)->stream($pdf->filename($bill));
    }

    /**
     * One invoice for however many bills were ticked.
     */
    public function checkout(CheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        $validated = $request->validated();

        try {
            $payment = $checkout->start(
                $request->user(),
                $validated['bill_ulids'],
                $validated['method'],
                $validated['custom_amounts'] ?? [],
                $validated['bank'] ?? 'muamalat',
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'payment' => new PaymentResource($payment),
        ], 201);
    }

    public function payments(Request $request): JsonResponse
    {
        // Paginated (audit T54-6): the old limit(100) cap silently truncated
        // a long history with no page, no note - the family simply stopped
        // seeing their own older payments.
        $payments = Payment::query()
            ->visibleTo($request->user())
            ->with(['bills.feeType', 'bills.student.schoolUnit'])
            ->latest()
            ->paginate($request->integer('per_page', 50));

        return response()->json(['payments' => PaymentResource::collection($payments)->response()->getData(true)]);
    }

    /**
     * The bell's 60-second poll (T67-c): a handful of integers instead of
     * the open-bill rows + payments page it used to drag down just to
     * decide whether the badge changed. The client compares the returned
     * signature (counts + latest_change_at) and only refetches the heavy
     * lists when something actually moved - or when the dropdown opens.
     *
     * The 7-day receipts window mirrors the frontend's
     * RECEIPT_WINDOW_DAYS; keeping the constant here too is deliberate,
     * since the count and the list must agree about what "recent" means.
     */
    public function bellSummary(Request $request): JsonResponse
    {
        $open = Bill::query()
            ->visibleTo($request->user())
            ->open()
            ->get(['id', 'status', 'remaining_amount', 'updated_at']);

        // Rolling 7 days, NOT startOfDay-aligned: the frontend filters the
        // receipts list by an exact millisecond cutoff, and a calendar-day
        // window here made the badge count a receipt the list then hid
        // (audit 2026-09-28).
        $recentPayments = Payment::query()
            ->visibleTo($request->user())
            ->where('status', 'completed')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', now()->subDays(7));

        $receiptsCount = (clone $recentPayments)->count();
        // A query-builder max() returns a raw string; the collection max()
        // over the bills side returns Carbon. Mixed, ->max() hands back the
        // string - and $latestChangeAt?->toIso8601String() then fatals on
        // it exactly when the family has no open bills but a fresh receipt
        // (the endpoint's flagship scenario). Parse to Carbon first.
        $latestPaymentRaw = (clone $recentPayments)->max('paid_at');
        $latestPaymentAt = $latestPaymentRaw !== null ? Carbon::parse((string) $latestPaymentRaw) : null;

        $latestChangeAt = collect([$open->max('updated_at'), $latestPaymentAt])->filter()->max();

        $since = $request->string('since')->value();
        $changedSince = null;

        if ($since !== '') {
            try {
                $changedSince = $latestChangeAt === null ? false : $latestChangeAt->isAfter(Carbon::parse($since));
            } catch (\Carbon\Exceptions\InvalidFormatException) {
                // A malformed ?since= is ignored rather than failing the
                // poll - the signature comparison still works without it.
            }
        }

        return response()->json([
            'open_count' => $open->count(),
            'overdue_count' => $open->where('status', 'overdue')->count(),
            'outstanding' => (float) $open->sum('remaining_amount'),
            'receipts_count' => $receiptsCount,
            'latest_change_at' => $latestChangeAt?->toIso8601String(),
            'changed_since' => $changedSince,
        ]);
    }

    /**
     * Dev-only: settles a payment without any money having moved.
     */
    public function simulateSettle(Request $request, string $ulid, \App\Services\Billing\PaymentAllocator $allocator): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $payment = Payment::query()
            ->visibleTo($request->user())
            ->where('ulid', $ulid)
            ->firstOrFail();

        if ($payment->status === 'completed') {
            return response()->json([
                'message' => 'Pembayaran ini sudah berstatus lunas.',
                'payment' => new PaymentResource($payment),
            ]);
        }

        $allocator->settle($payment, 'tx_sim_'.uniqid(), [
            'simulated' => true,
            'actor' => $request->user()->name,
            'settled_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'message' => 'Pembayaran berhasil diverifikasi & status tagihan telah LUNAS!',
            'payment' => new PaymentResource($payment->fresh(['bills.feeType', 'bills.student.schoolUnit'])),
        ]);
    }
}
