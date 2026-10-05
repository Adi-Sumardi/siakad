<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\Billing\PaymentReceiptPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The finance office's transaction ledger (feature batch Poin 11A/B):
 * every payment in scope, filterable the way a reconciliation actually
 * needs - by number (ours OR the VA reference), date range, unit, fee
 * type, status, and bank channel. Per-unit admins stay inside their own
 * unit through Payment::visibleTo(), the same scope the rest of the
 * billing surfaces use.
 */
class PaymentHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->visibleTo($request->user())
            ->when($request->string('status')->value(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->string('from')->value(), fn ($q, $from) => $q->where('paid_at', '>=', $from.' 00:00:00'))
            ->when($request->string('to')->value(), fn ($q, $to) => $q->where('paid_at', '<=', $to.' 23:59:59'))
            ->when($request->string('method')->value(), fn ($q, $method) => $q->where('method', $method))
            ->when($request->string('channel')->value(), fn ($q, $channel) => $q->where('channel', $channel))
            ->when($request->string('unit')->value(), fn ($q, $code) => $q->whereHas(
                'bills.student.schoolUnit',
                fn ($u) => $u->where('code', $code),
            ))
            ->when($request->string('fee_type')->value(), fn ($q, $code) => $q->whereHas(
                'bills.feeType',
                fn ($t) => $t->where('code', $code),
            ))
            ->when($q0 = $request->string('q')->value(), function ($q, $q0) {
                // Relational matching only - the gateway_response JSON is
                // not queried (SQLite/Postgres JSON grammar differs); the
                // VA number lives on bills' checkout payments and the
                // reference resolves through referenceNumber()'s sources.
                $q->where(function ($sq) use ($q0) {
                    $sq->where('payment_number', 'like', "%{$q0}%")
                        ->orWhereHas('bills', fn ($b) => $b->where('bill_number', 'like', "%{$q0}%"))
                        ->orWhereHas('bills.student', fn ($s) => $s
                            ->where('nama_lengkap', 'like', "%{$q0}%")
                            ->orWhere('nis', 'like', "%{$q0}%"));
                });
            })
            ->with(['bills.feeType', 'bills.student.schoolUnit', 'payer'])
            ->latest('paid_at')
            ->paginate(\App\Support\PerPage::clamp($request, 25));

        return response()->json(['payments' => PaymentResource::collection($payments)->response()->getData(true)]);
    }

    /**
     * The per-PAYMENT receipt PDF (Poin 11B) - one payment can settle
     * several bills at a custom amount, so the per-bill invoice would
     * print the wrong basket; this renders exactly what was paid.
     */
    public function receiptPdf(Request $request, string $ulid): StreamedResponse
    {
        $payment = Payment::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        $pdf = app(PaymentReceiptPdfService::class)->render($payment);

        return response()->streamDownload(
            fn () => print($pdf->output()),
            'Pembayaran-'.str_replace('/', '-', $payment->referenceNumber()).'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Lazily mint the public receipt link (Poin 11C) for settled payments:
     * history that predates the token column gets one on first share, and
     * an existing token is returned unchanged - the link must be stable
     * once a family has it.
     */
    public function shareLink(Request $request, string $ulid): JsonResponse
    {
        $payment = Payment::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        if ($payment->status !== 'completed') {
            return response()->json(['message' => 'Tautan struk hanya tersedia untuk pembayaran yang sudah selesai.'], 422);
        }

        if (! $payment->receipt_public_token) {
            // Under a row lock (audit 2026-09-28): two admins clicking
            // "share" concurrently both saw a null token and both minted -
            // the second save won and the first admin handed out a dead
            // link. The lock serializes the check; the loser re-reads the
            // winner's token and returns the SAME stable link.
            $payment = DB::transaction(function () use ($payment) {
                $fresh = Payment::query()->lockForUpdate()->whereKey($payment->id)->firstOrFail();

                if (! $fresh->receipt_public_token) {
                    $fresh->forceFill(['receipt_public_token' => \Illuminate\Support\Str::random(32)])->save();
                }

                return $fresh;
            });

            // Minting a public receipt credential is a money-adjacent act
            // (audit 2026-10-05 r2): every other lane that hands something
            // out logs it, and log-aktivitas is where "who shared this
            // family's receipt link" should be answerable from.
            \App\Models\ActivityLog::record($request->user(), 'payment.share_link_minted', $payment, [
                'payment' => $payment->payment_number,
            ]);
        }

        return response()->json(['url' => "/receipt/{$payment->receipt_public_token}"]);
    }

    /**
     * The refund worklist (audit 2026-10-05 P0): payments whose money
     * arrived for bills another completed payment had already covered.
     * settle() books them completed but flags their allocations
     * applies_to_bill=false, so "what TU still owes back" is exactly this
     * set - completed, carrying the overpayment annotation, with at least
     * one unapplied allocation. Scoping stays visibleTo: a unit admin sees
     * their own unit's refunds only.
     */
    public function overpayments(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->visibleTo($request->user())
            ->where('status', 'completed')
            ->whereHas('allocations', fn ($q) => $q->where('applies_to_bill', false))
            ->with(['bills.feeType', 'bills.student.schoolUnit', 'payer'])
            ->latest('paid_at')
            ->paginate(\App\Support\PerPage::clamp($request, 25));

        return response()->json(['payments' => PaymentResource::collection($payments)->response()->getData(true)]);
    }

    /**
     * Marks a pure overpayment refunded (audit 2026-10-05 P0): the money
     * settled nothing (every allocation unapplied), so flipping the status
     * changes no bill's arithmetic - recompute() only ever counted
     * completed payments' applied allocations. Restricted to pure
     * overpayments on purpose: refunding part of a payment that DID settle
     * a bill is a different bookkeeping operation (it would reopen the
     * bill) and is not this button.
     */
    public function refund(Request $request, string $ulid): JsonResponse
    {
        $payment = Payment::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        if ($payment->status !== 'completed' || ! isset($payment->metadata['overpayment'])) {
            return response()->json(['message' => 'Pembayaran ini bukan overpayment yang menunggu refund.'], 422);
        }

        $hasAppliedAllocation = $payment->allocations()->where('applies_to_bill', true)->exists();

        if ($hasAppliedAllocation) {
            return response()->json(['message' => 'Pembayaran ini sebagian melunasi tagihan - refund parsial harus diproses manual oleh pusat.'], 422);
        }

        // The claim returns whether THIS call wrote the decision (audit
        // 2026-10-05 r2): the old closure returned silently on a lost race
        // and the method still answered 200 "ditandai refunded" though
        // nothing was written - and a concurrent loser's firstOrFail
        // surfaced as a raw 404 mid-transaction. Now both races answer a
        // clean 422.
        $refunded = DB::transaction(function () use ($payment, $request) {
            // Claim under a row lock, same discipline as settle()/fail():
            // two admins clicking refund concurrently, or a refund racing
            // some future lane, must write this decision exactly once.
            $fresh = Payment::query()
                ->whereKey($payment->id)
                ->where('status', 'completed')
                ->lockForUpdate()
                ->first();

            $overpayment = $fresh?->metadata['overpayment'] ?? null;

            if (! $fresh || ! $overpayment) {
                return false;
            }

            // The annotation moves aside rather than vanishing: the worklist
            // keys off the unapplied allocations, and the audit trail of
            // what was owed and when stays readable on the row itself.
            $metadata = $fresh->metadata ?? [];
            unset($metadata['overpayment']);
            $metadata['refunded'] = [
                'overpayment' => $overpayment,
                'refunded_at' => now()->toIso8601String(),
                'refunded_by' => $request->user()->name,
            ];

            $fresh->forceFill([
                'status' => 'refunded',
                'failed_at' => now(),
                'rejection_reason' => 'Overpayment direfund oleh TU.',
                'metadata' => $metadata,
            ])->save();

            // The money-return decision belongs in log-aktivitas beside
            // every other money lane (audit 2026-10-05 r2) - waived and
            // cancelled bills, billing runs, VA issues all log theirs.
            \App\Models\ActivityLog::record($request->user(), 'payment.refunded', $fresh, [
                'payment' => $fresh->payment_number,
                'amount' => (float) $fresh->amount,
                'refunded_by' => $request->user()->name,
            ]);

            return true;
        });

        if (! $refunded) {
            return response()->json(['message' => 'Pembayaran ini sudah direfund atau bukan overpayment yang menunggu refund.'], 422);
        }

        return response()->json(['message' => 'Pembayaran ditandai refunded. Pastikan transfer pengembalian dana sudah dikirim.']);
    }
}
