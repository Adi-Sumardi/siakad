<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\Billing\PaymentReceiptPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->paginate($request->integer('per_page', 25));

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
            $payment->forceFill(['receipt_public_token' => \Illuminate\Support\Str::random(32)])->save();
        }

        return response()->json(['url' => "/receipt/{$payment->receipt_public_token}"]);
    }
}
