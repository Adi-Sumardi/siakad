<?php

namespace App\Services\Billing;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for interacting with the e-SPP Billing API (Webservice).
 * Base URL: http://43.225.66.150:8061
 */
class BillingApiClient
{
    // Default Virtual Account 6-digit Prefixes for Bank Muamalat (BMI - Kode Bank 147)
    public const PREFIX_SPP = '802001';
    public const PREFIX_UANG_PANGKAL = '802002';
    public const PREFIX_JAMIYYAH = '802003';
    public const PREFIX_PENDAFTARAN = '802004';
    public const PREFIX_EKSKUL_TK = '802005';
    public const PREFIX_EKSKUL_SD = '802006';
    public const PREFIX_EKSKUL_SMP12 = '802007';
    public const PREFIX_EKSKUL_SMP55 = '802008';

    // Default Virtual Account 6-digit Prefixes for Bank Syariah Indonesia (BSI - Kode Bank 451)
    public const PREFIX_BSI_SPP = '365601';
    public const PREFIX_BSI_UANG_PANGKAL = '365602';
    public const PREFIX_BSI_JAMIYYAH = '365603';
    public const PREFIX_BSI_PENDAFTARAN = '365604';
    public const PREFIX_BSI_EKSKUL_TK = '365605';
    public const PREFIX_BSI_EKSKUL_SD = '365606';
    public const PREFIX_BSI_EKSKUL_SMP12 = '365607';
    public const PREFIX_BSI_EKSKUL_SMP55 = '365608';

    private const TOKEN_CACHE_KEY = 'billing_api:access_token';
    private const TOKEN_EXPIRY_BUFFER_SECONDS = 300;

    /**
     * Resolves the 6-digit VA prefix based on fee type code, school unit, and bank.
     */
    public static function resolvePrefix(string $feeTypeCode, ?SchoolUnit $unit = null, string $bank = 'muamalat'): string
    {
        $normalizedFee = strtolower($feeTypeCode);
        $bankKey = strtolower($bank) === 'bsi' ? 'bsi' : 'muamalat';

        if (str_contains($normalizedFee, 'ekskul')) {
            $unitCode = strtoupper((string) ($unit?->code ?? ''));

            return match (true) {
                str_contains($unitCode, 'TK') => (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.ekskul_tk", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_TK : self::PREFIX_EKSKUL_TK),
                str_contains($unitCode, 'SD') => (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.ekskul_sd", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_SD : self::PREFIX_EKSKUL_SD),
                str_contains($unitCode, 'SMP-12') || str_contains($unitCode, 'SMP12') => (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.ekskul_smp12", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_SMP12 : self::PREFIX_EKSKUL_SMP12),
                str_contains($unitCode, 'SMP-55') || str_contains($unitCode, 'SMP55') => (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.ekskul_smp55", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_SMP55 : self::PREFIX_EKSKUL_SMP55),
                default => (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.ekskul_sd", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_SD : self::PREFIX_EKSKUL_SD),
            };
        }

        if (str_contains($normalizedFee, 'jamiyyah') || str_contains($normalizedFee, 'jam')) {
            return (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.jamiyyah", $bankKey === 'bsi' ? self::PREFIX_BSI_JAMIYYAH : self::PREFIX_JAMIYYAH);
        }

        if (str_contains($normalizedFee, 'pangkal')) {
            return (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.uang_pangkal", $bankKey === 'bsi' ? self::PREFIX_BSI_UANG_PANGKAL : self::PREFIX_UANG_PANGKAL);
        }

        if (str_contains($normalizedFee, 'pendaftaran') || str_contains($normalizedFee, 'formulir')) {
            return (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.pendaftaran", $bankKey === 'bsi' ? self::PREFIX_BSI_PENDAFTARAN : self::PREFIX_PENDAFTARAN);
        }

        return (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.spp", $bankKey === 'bsi' ? self::PREFIX_BSI_SPP : self::PREFIX_SPP);
    }

    /**
     * Generates a full 16-digit Virtual Account number.
     * [Prefix 6-digit] + [Tahun Ajaran 4-digit] + [Nomor Siswa 6-digit] = 16 digits.
     */
    public static function generateVaNumber(Student $student, Bill|FeeType|string $billOrType, string $bank = 'muamalat'): string
    {
        $feeTypeCode = 'spp';
        $academicYear = null;

        if ($billOrType instanceof Bill) {
            $feeTypeCode = $billOrType->feeType?->code ?? 'spp';
            $academicYear = $billOrType->academicYear?->year;
        } elseif ($billOrType instanceof FeeType) {
            $feeTypeCode = $billOrType->code;
        } elseif (is_string($billOrType)) {
            $feeTypeCode = $billOrType;
        }

        $prefix = self::resolvePrefix($feeTypeCode, $student->schoolUnit, $bank);
        $academicYearCode = self::formatAcademicYearCode($academicYear ?: $student->entryYear?->year);
        $studentSeq = self::formatStudentCode($student);

        return $prefix . $academicYearCode . $studentSeq;
    }

    /**
     * Extracts 4-digit code from academic year (e.g. '2027/2028' -> '2728', '2026/2027' -> '2627').
     */
    public static function formatAcademicYearCode(?string $academicYear): string
    {
        if ($academicYear && preg_match('/(\d{4})\/(\d{4})/', $academicYear, $m)) {
            return substr($m[1], 2, 2) . substr($m[2], 2, 2);
        }

        if ($academicYear && preg_match('/(\d{2})\/(\d{2})/', $academicYear, $m)) {
            return $m[1] . $m[2];
        }

        if ($academicYear && preg_match('/^\d{4}$/', $academicYear)) {
            return $academicYear;
        }

        try {
            $activeYear = AcademicYear::where('is_active', true)->value('year');
            if ($activeYear && preg_match('/(\d{4})\/(\d{4})/', $activeYear, $m)) {
                return substr($m[1], 2, 2) . substr($m[2], 2, 2);
            }
        } catch (\Throwable) {
            // Ignore if DB not queryable
        }

        return '2728';
    }

    /**
     * Formats the student's identifier into a 6-digit numerical string for
     * the VA number.
     */
    public static function formatStudentCode(Student $student): string
    {
        return str_pad((string) $student->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Sanitizes customer name for core banking and ATM compatibility.
     * Removes unsupported special characters and trims to max 30 characters.
     */
    public static function sanitizeCustomerName(?string $name): string
    {
        if (! $name) {
            return 'Siswa YAPI';
        }

        $clean = preg_replace('/[^\p{L}\p{N}\s\.\-]/u', ' ', $name);
        $clean = preg_replace('/\s+/', ' ', trim($clean ?? ''));

        if (mb_strlen($clean) > 30) {
            $clean = mb_substr($clean, 0, 30);
        }

        return $clean ?: 'Siswa YAPI';
    }

    public function getAccessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        return $cached ?: $this->requestNewAccessToken();
    }

    // Endpoint is /api/login with a plain JSON body (client_id/client_secret/
    // username/password, no grant_type) - NOT /oauth/token with form-encoded
    // password-grant fields. A prior rewrite swapped to the OAuth shape
    // without confirming it against e-SPP's actual docs; PMB verified /api/login
    // against the real API documentation (section 5.1) and this mirrors that.
    private function requestNewAccessToken(): string
    {
        $baseUrl = rtrim((string) config('services.billing_api.base_url', 'http://43.225.66.150:8061'), '/');
        $clientId = config('services.billing_api.client_id');
        $clientSecret = config('services.billing_api.client_secret');
        $username = config('services.billing_api.username');
        $password = config('services.billing_api.password');

        if (! $clientId || ! $clientSecret || ! $username || ! $password) {
            throw new BillingApiException('Billing API credentials are not fully configured in services.billing_api.');
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->post("{$baseUrl}/api/login", [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'username' => $username,
                'password' => $password,
            ]);

        if ($response->failed()) {
            Log::error('[BillingApiClient] Login request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new BillingApiException(
                'Failed to authenticate with e-SPP Billing API: ' . $response->body(),
                $response->status()
            );
        }

        $token = $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 0);

        if (! $token) {
            throw new BillingApiException('Access token missing from login response.');
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, max($expiresIn - self::TOKEN_EXPIRY_BUFFER_SECONDS, 60));

        return $token;
    }

    private function client(): PendingRequest
    {
        $baseUrl = rtrim((string) config('services.billing_api.base_url', 'http://43.225.66.150:8061'), '/');
        $token = $this->getAccessToken();

        return Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * Creates a new billing record on e-SPP. One bill belongs to exactly one
     * bank_id (main_form.bank_id is singular) - bmi/bsm are NOT "one bank's
     * VA in each": both are payment-info blocks for this SAME bill (docs
     * section 5.3). Endpoint is /api/billing wrapped in a main_form key, not
     * the flattened /api/billing/create a prior rewrite introduced without
     * confirming against e-SPP's docs.
     *
     * @param array<string, mixed> $mainForm customer_name, va_desc, va_desc1, jumlah_tagihan, date_start, date_end, priority, pay_type, sekolah, kelas. bank_id falls back to config('services.billing_api.bank_id') when omitted.
     * @param array<string, mixed> $bmi va_number, ref_number
     * @param array<string, mixed> $bsm nomor_pembayaran, id_tagihan
     */
    public function createBilling(array $mainForm, array $bmi, array $bsm): array
    {
        $mainForm += ['bank_id' => (string) config('services.billing_api.bank_id', '1')];

        $payload = [
            'main_form' => $mainForm,
            'bmi' => $bmi,
            'bsm' => $bsm,
        ];

        $response = $this->client()->post('/api/billing', $payload);

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->client()->post('/api/billing', $payload);
        }

        if ($response->failed()) {
            Log::error('[BillingApiClient] createBilling failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new BillingApiException(
                'e-SPP createBilling failed: ' . $response->body(),
                $response->status()
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Looks up an existing billing by its Bank Muamalat Virtual Account Number.
     */
    public function getByVaNumber(string $vaNumber): array
    {
        $response = $this->client()->get("/api/billing/va/{$vaNumber}");

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->client()->get("/api/billing/va/{$vaNumber}");
        }

        if ($response->failed()) {
            throw new BillingApiException(
                "e-SPP getByVaNumber failed for VA {$vaNumber}: " . $response->body(),
                $response->status()
            );
        }

        return $response->json() ?? [];
    }
}
