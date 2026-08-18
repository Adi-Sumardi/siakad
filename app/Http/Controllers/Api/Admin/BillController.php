<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillResource;
use App\Http\Resources\PaymentResource;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Services\Billing\BillPdfService;
use App\Services\Billing\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class BillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bills = Bill::query()
            ->visibleTo($request->user())
            ->with(['student.schoolUnit', 'feeType'])
            ->when($request->string('status')->value(), fn ($q, $status) => $status === 'open'
                ? $q->open()
                : $q->where('status', $status))
            ->when($request->string('type')->value(), fn ($q, $code) => $q->whereHas('feeType', fn ($t) => $t->where('code', $code)))
            ->when($request->integer('month'), fn ($q, $month) => $q->where('period_month', $month))
            ->when($request->string('q')->value(), fn ($q, $term) => $q->whereHas('student',
                fn ($s) => $s->where('nama_lengkap', 'like', "%{$term}%")))
            ->orderBy('due_date')
            ->paginate(50);

        return response()->json([
            'bills' => BillResource::collection($bills)->response()->getData(true),
        ]);
    }

    public function pdf(Request $request, string $ulid, BillPdfService $pdf): Response
    {
        $bill = Bill::query()
            ->visibleTo($request->user())
            ->where('ulid', $ulid)
            ->firstOrFail();

        return $pdf->render($bill)->stream($pdf->filename($bill));
    }

    /**
     * Writes the bill off. Requires a reason, because the alternative is a
     * balance that silently disappeared and nobody can account for at audit.
     */
    public function waive(Request $request, string $ulid): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        $bill = Bill::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        if (! $bill->isOpen()) {
            return response()->json(['message' => 'Hanya tagihan yang belum lunas yang bisa dibebaskan.'], 422);
        }

        $bill->forceFill([
            'status' => 'waived',
            'remaining_amount' => 0,
            'notes' => trim(($bill->notes ? $bill->notes."\n" : '').'Dibebaskan: '.$validated['reason']),
        ])->save();

        ActivityLog::record($request->user(), 'bill.waived', $bill, [
            'bill_number' => $bill->bill_number,
            'amount' => (float) $bill->total_amount,
            'reason' => $validated['reason'],
        ]);

        return response()->json(['bill' => new BillResource($bill->fresh())]);
    }

    public function cancel(Request $request, string $ulid): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        $bill = Bill::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        if ((float) $bill->paid_amount > 0) {
            // Money has already been received against it; cancelling would
            // strand that payment with nothing to point at.
            return response()->json([
                'message' => 'Tagihan yang sudah menerima pembayaran tidak bisa dibatalkan. Gunakan refund.',
            ], 422);
        }

        $bill->forceFill([
            'status' => 'cancelled',
            'remaining_amount' => 0,
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
            'cancel_reason' => $validated['reason'],
        ])->save();

        ActivityLog::record($request->user(), 'bill.cancelled', $bill, [
            'bill_number' => $bill->bill_number,
            'reason' => $validated['reason'],
        ]);

        return response()->json(['bill' => new BillResource($bill->fresh())]);
    }

    /** Cash at the front desk, or a transfer the admin has already confirmed. */
    public function recordPayment(Request $request, string $ulid, CheckoutService $checkout): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'method' => 'required|in:cash,bank_transfer,qris,other',
            'notes' => 'nullable|string|max:500',
        ]);

        $bill = Bill::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        try {
            $payment = $checkout->recordManual(
                $bill,
                (float) $validated['amount'],
                $validated['method'],
                $request->user(),
                $bill->student->guardians()->wherePivot('is_billing_contact', true)->first(),
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLog::record($request->user(), 'payment.recorded_manually', $payment, [
            'bill_number' => $bill->bill_number,
            'amount' => (float) $validated['amount'],
            'method' => $validated['method'],
        ]);

        return response()->json([
            'payment' => new PaymentResource($payment),
            'bill' => new BillResource($bill->fresh()),
        ], 201);
    }
}
