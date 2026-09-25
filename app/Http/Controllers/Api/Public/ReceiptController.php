<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;

/**
 * The public receipt endpoint (feature batch Poin 11C): a shareable,
 * login-free proof of payment for families. Security rests entirely on
 * the token's 32 characters of CSPRNG entropy (the school's explicit
 * choice: no expiry, no secondary check) plus a deliberately MINIMAL
 * payload - what was paid, when, for which child and bills. No NIS, no
 * contacts, no ULIDs, nothing enumeration-adjacent; a wrong token is an
 * undifferentiated 404.
 */
class ReceiptController extends Controller
{
    public function show(string $token): JsonResponse
    {
        if (! preg_match('/^[A-Za-z0-9]{32}$/', $token)) {
            abort(404);
        }

        $payment = Payment::query()
            ->where('receipt_public_token', $token)
            ->where('status', 'completed')
            ->firstOrFail();

        $payment->loadMissing(['bills.feeType', 'bills.student.schoolUnit']);

        return response()->json([
            'receipt' => [
                'reference_number' => $payment->referenceNumber(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'amount' => (float) $payment->amount,
                'bank_name' => (string) ($payment->gateway_response['bank_name'] ?? 'Virtual Account'),
                'students' => $payment->bills
                    ->map(fn ($bill) => $bill->student)
                    ->unique('id')
                    ->values()
                    ->map(fn ($student) => [
                        'nama_lengkap' => $student->nama_lengkap,
                        'unit' => $student->schoolUnit?->label,
                    ])
                    ->all(),
                'items' => $payment->allocations()
                    ->with('bill.feeType')
                    ->get()
                    ->map(fn ($allocation) => [
                        'description' => $allocation->bill?->description,
                        'fee_type' => $allocation->bill?->feeType?->name,
                        'amount' => (float) $allocation->amount,
                    ])
                    ->all(),
                'status' => 'LUNAS',
            ],
        ]);
    }
}
