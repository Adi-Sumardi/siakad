<?php

namespace App\Services\Payment;

use App\Models\Bill;
use App\Models\Guardian;
use App\Models\Payment;
use App\Services\Billing\BillingApiClient;
use App\Services\Billing\BillingApiException;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Multi-Bank Virtual Account Payment Gateway Integration (Bank Muamalat & BSI) via e-SPP webservice.
 */
class BillingApiGateway implements PaymentGateway
{
    public function __construct(
        private BillingApiClient $client,
    ) {}

    public function createInvoice(Payment $payment, Collection $bills, Guardian $payer): Payment
    {
        $primaryBill = $bills->first();
        $student = $primaryBill?->student;

        if (! $student) {
            throw new RuntimeException('Data siswa tidak ditemukan pada tagihan yang dipilih.');
        }

        $feeTypeCode = $primaryBill->feeType?->code ?? 'spp';
        $selectedBank = strtolower((string) ($payment->metadata['bank_channel'] ?? 'muamalat'));
        if (! in_array($selectedBank, ['muamalat', 'bsi'], true)) {
            $selectedBank = 'muamalat';
        }

        $vaMuamalat = BillingApiClient::generateVaNumber($student, $primaryBill, 'muamalat');
        $vaBsi = BillingApiClient::generateVaNumber($student, $primaryBill, 'bsi');
        $primaryVa = $selectedBank === 'bsi' ? $vaBsi : $vaMuamalat;

        $bankConfig = config("services.banks.{$selectedBank}") ?? [
            'name' => $selectedBank === 'bsi' ? 'Bank Syariah Indonesia (BSI)' : 'Bank Muamalat',
            'code' => $selectedBank === 'bsi' ? '451' : '147',
        ];

        $dueDays = (int) config('services.billing_api.va_due_days', 3);
        $dueDate = now()->addDays($dueDays);

        // Synchronize payment_number with fee type and student code if not already formatted
        $studentCode = BillingApiClient::formatStudentCode($student);
        $prefixRef = match (true) {
            str_contains($feeTypeCode, 'ekskul') => 'YAPI-EKS',
            str_contains($feeTypeCode, 'jamiyyah') => 'YAPI-JAM',
            str_contains($feeTypeCode, 'spp') => 'YAPI-SPP',
            default => 'YAPI-PAY',
        };
        $year = date('Y');
        $customPaymentNumber = sprintf('%s-%s-%s', $prefixRef, $year, $studentCode);

        // Ensure unique payment_number
        if ($payment->payment_number !== $customPaymentNumber) {
            if (Payment::where('payment_number', $customPaymentNumber)->where('id', '!=', $payment->id)->exists()) {
                $suffix = 2;
                while (Payment::where('payment_number', "{$customPaymentNumber}-{$suffix}")->where('id', '!=', $payment->id)->exists()) {
                    $suffix++;
                }
                $customPaymentNumber = "{$customPaymentNumber}-{$suffix}";
            }
            $payment->payment_number = $customPaymentNumber;
        }

        $description = $this->describe($bills, $student);
        $customerName = BillingApiClient::sanitizeCustomerName($student->nama_lengkap);

        try {
            $response = $this->client->createBilling(
                [
                    'customer_name' => $customerName,
                    'va_desc' => $description,
                    'va_desc1' => $student->schoolUnit?->label ?? '',
                    'jumlah_tagihan' => (int) $payment->amount,
                    'date_start' => now()->toDateString(),
                    'date_end' => $dueDate->toDateString(),
                    'priority' => '1',
                    'pay_type' => 'full',
                    'sekolah' => $student->schoolUnit?->label ?? '',
                    'kelas' => $student->currentEnrollment()?->classroom?->name ?? '',
                ],
                ['va_number' => $vaMuamalat, 'ref_number' => $payment->payment_number],
                ['nomor_pembayaran' => $vaBsi, 'id_tagihan' => $vaBsi]
            );

            $rawUuid = $response['uuid'] ?? ($response['data']['uuid'] ?? null);
            $billingUuid = is_array($rawUuid) ? ($rawUuid['string'] ?? null) : $rawUuid;

            $gatewayResponse = [
                'provider' => 'bank_' . $selectedBank,
                'bank_key' => $selectedBank,
                'va_number' => $primaryVa,
                'bank_name' => (string) ($bankConfig['name'] ?? ($selectedBank === 'bsi' ? 'Bank Syariah Indonesia (BSI)' : 'Bank Muamalat')),
                'bank_code' => (string) ($bankConfig['code'] ?? ($selectedBank === 'bsi' ? '451' : '147')),
                'all_va' => [
                    'muamalat' => $vaMuamalat,
                    'bsi' => $vaBsi,
                ],
                'amount' => (float) $payment->amount,
                'due_date' => $dueDate->toDateString(),
                'billing_uuid' => $billingUuid,
                'customer_name' => $customerName,
                'student_name' => $student->nama_lengkap,
                'unit' => $student->schoolUnit?->label,
                'fee_type' => $primaryBill->feeType?->name,
                'raw' => $response,
            ];

            $payment->forceFill([
                'status' => 'processing',
                'external_transaction_id' => $billingUuid ?: $primaryVa,
                'invoice_id' => $billingUuid ?: $primaryVa,
                'invoice_url' => null,
                'expires_at' => $dueDate,
                'gateway_response' => $gatewayResponse,
            ])->save();

            return $payment;
        } catch (BillingApiException $e) {
            Log::error('[BillingApiGateway] Create billing failed', [
                'payment' => $payment->payment_number,
                'va_number' => $primaryVa,
                'error' => $e->getMessage(),
                'status' => $e->statusCode(),
            ]);

            // If API key/connection not provisioned yet in local development, provide fallback VA info
            if (! app()->isProduction()) {
                $gatewayResponse = [
                    'provider' => 'bank_' . $selectedBank,
                    'bank_key' => $selectedBank,
                    'va_number' => $primaryVa,
                    'bank_name' => (string) ($bankConfig['name'] ?? ($selectedBank === 'bsi' ? 'Bank Syariah Indonesia (BSI)' : 'Bank Muamalat')),
                    'bank_code' => (string) ($bankConfig['code'] ?? ($selectedBank === 'bsi' ? '451' : '147')),
                    'all_va' => [
                        'muamalat' => $vaMuamalat,
                        'bsi' => $vaBsi,
                    ],
                    'amount' => (float) $payment->amount,
                    'due_date' => $dueDate->toDateString(),
                    'billing_uuid' => 'sim_'.uniqid(),
                    'customer_name' => $customerName,
                    'student_name' => $student->nama_lengkap,
                    'unit' => $student->schoolUnit?->label,
                    'fee_type' => $primaryBill->feeType?->name,
                    'simulated' => true,
                ];

                $payment->forceFill([
                    'status' => 'processing',
                    'external_transaction_id' => $primaryVa,
                    'invoice_id' => $primaryVa,
                    'invoice_url' => null,
                    'expires_at' => $dueDate,
                    'gateway_response' => $gatewayResponse,
                ])->save();

                return $payment;
            }

            throw new RuntimeException('Gagal membuat tagihan Virtual Account: '.$e->getMessage());
        }
    }

    private function describe(Collection $bills, ?\App\Models\Student $student): string
    {
        if ($bills->count() === 1) {
            $desc = (string) $bills->first()->description;
        } else {
            $desc = $bills->count().' tagihan ('.$bills->pluck('description')->take(2)->join(', ').'…)';
        }

        return $student ? "{$desc} - {$student->nama_lengkap}" : $desc;
    }
}
