<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillResource;
use App\Http\Resources\PaymentResource;
use App\Models\Bill;
use App\Models\Payment;
use App\Services\Billing\BillPdfService;
use App\Services\Billing\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->with(['student', 'feeType', 'lines'])
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
    public function checkout(Request $request, CheckoutService $checkout): JsonResponse
    {
        $validated = $request->validate([
            'bill_ulids' => 'required|array|min:1|max:50',
            'bill_ulids.*' => 'required|string',
            'method' => 'required|in:virtual_account,e_wallet,qris,bank_transfer,credit_card',
            'bank' => 'nullable|in:muamalat,bsi',
            'custom_amounts' => 'nullable|array',
            'custom_amounts.*' => 'numeric|min:1',
        ]);

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
        $payments = Payment::query()
            ->visibleTo($request->user())
            ->with(['bills.feeType', 'bills.student.schoolUnit'])
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['payments' => PaymentResource::collection($payments)]);
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
