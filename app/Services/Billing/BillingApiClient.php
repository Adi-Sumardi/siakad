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
                str_contains($unitCode, 'TK') => (string) config("services.banks.{$bankKey}.prefixes.ekskul_tk", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_TK : self::PREFIX_EKSKUL_TK),
                str_contains($unitCode, 'SD') => (string) config("services.banks.{$bankKey}.prefixes.ekskul_sd", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_SD : self::PREFIX_EKSKUL_SD),
                str_contains($unitCode, 'SMP-12') || str_contains($unitCode, 'SMP12') => (string) config("services.banks.{$bankKey}.prefixes.ekskul_smp12", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_SMP12 : self::PREFIX_EKSKUL_SMP12),
                str_contains($unitCode, 'SMP-55') || str_contains($unitCode, 'SMP55') => (string) config("services.banks.{$bankKey}.prefixes.ekskul_smp55", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_SMP55 : self::PREFIX_EKSKUL_SMP55),
                default => (string) config("services.banks.{$bankKey}.prefixes.ekskul_sd", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_SD : self::PREFIX_EKSKUL_SD),
            };
        }

        if (str_contains($normalizedFee, 'jamiyyah') || str_contains($normalizedFee, 'jam')) {
            return (string) config("services.banks.{$bankKey}.prefixes.jamiyyah", $bankKey === 'bsi' ? self::PREFIX_BSI_JAMIYYAH : self::PREFIX_JAMIYYAH);
        }

        if (str_contains($normalizedFee, 'pangkal')) {
            return (string) config("services.banks.{$bankKey}.prefixes.uang_pangkal", $bankKey === 'bsi' ? self::PREFIX_BSI_UANG_PANGKAL : self::PREFIX_UANG_PANGKAL);
        }

        if (str_contains($normalizedFee, 'pendaftaran') || str_contains($normalizedFee, 'formulir')) {
            return (string) config("services.banks.{$bankKey}.prefixes.pendaftaran", $bankKey === 'bsi' ? self::PREFIX_BSI_PENDAFTARAN : self::PREFIX_PENDAFTARAN);
        }

        return (string) config("services.banks.{$bankKey}.prefixes.spp", $bankKey === 'bsi' ? self::PREFIX_BSI_SPP : self::PREFIX_SPP);
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
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addSeconds(3600 - self::TOKEN_EXPIRY_BUFFER_SECONDS), function () {
            return $this->requestNewAccessToken();
        });
    }

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

        $response = Http::asForm()
            ->timeout(15)
            ->post("{$baseUrl}/oauth/token", [
                'grant_type' => 'password',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'username' => $username,
                'password' => $password,
            ]);

        if ($response->failed()) {
            Log::error('[BillingApiClient] OAuth token request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new BillingApiException(
                'Failed to authenticate with e-SPP Billing API: ' . $response->body(),
                $response->status()
            );
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;

        if (! $token) {
            throw new BillingApiException('Access token missing from OAuth response.');
        }

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
     * Creates a new billing record on e-SPP with multi-channel support (Muamalat & BSI).
     */
    public function createBilling(array $mainForm, array $bmi, array $bsm): array
    {
        $payload = array_merge($mainForm, [
            'bmi' => $bmi,
            'bsm' => $bsm,
        ]);

        $response = $this->client()->post('/api/billing/create', $payload);

        if ($response->failed()) {
            if ($response->status() === 401) {
                Cache::forget(self::TOKEN_CACHE_KEY);
            }

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

        if ($response->failed()) {
            if ($response->status() === 401) {
                Cache::forget(self::TOKEN_CACHE_KEY);
            }

            throw new BillingApiException(
                "e-SPP getByVaNumber failed for VA {$vaNumber}: " . $response->body(),
                $response->status()
            );
        }

        return $response->json() ?? [];
    }
}
