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
     *
     * Deliberately not filtered by the selected child: a parent wants to see
     * what the family owes and clear it in one go, and splitting the list per
     * child forces three checkouts and three bank fees.
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
     * PDF for this bill - an invoice while owed, a receipt once settled. Same
     * ownership check as everywhere else: visibleTo() before firstOrFail(), so
     * this can never be used to fetch another family's document.
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
     *
     * The list of ulids is re-checked against what this guardian may pay before
     * anything is created - see CheckoutService::collectPayable().
     */
    public function checkout(Request $request, CheckoutService $checkout): JsonResponse
    {
        $validated = $request->validate([
            'bill_ulids' => 'required|array|min:1|max:50',
            'bill_ulids.*' => 'required|string',
            'method' => 'required|in:virtual_account,e_wallet,qris,bank_transfer,credit_card',
            'custom_amounts' => 'nullable|array',
            'custom_amounts.*' => 'numeric|min:1',
        ]);

        try {
            $payment = $checkout->start(
                $request->user(),
                $validated['bill_ulids'],
                $validated['method'],
                $validated['custom_amounts'] ?? [],
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
            ->with(['bills.feeType', 'bills.student'])
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['payments' => PaymentResource::collection($payments)]);
    }
}
