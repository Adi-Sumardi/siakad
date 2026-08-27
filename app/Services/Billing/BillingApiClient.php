<?php

namespace App\Services\Billing;

use App\Models\Bill;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the e-SPP Billing API (Bank Muamalat BMI Virtual Account webservice).
 */
class BillingApiClient
{
    public const PREFIX_SPP = '802001';
    public const PREFIX_UANG_PANGKAL = '802002';
    public const PREFIX_JAMIYYAH = '802003';
    public const PREFIX_PENDAFTARAN = '802004';
    public const PREFIX_EKSKUL_TK = '802005';
    public const PREFIX_EKSKUL_SD = '802006';
    public const PREFIX_EKSKUL_SMP12 = '802007';
    public const PREFIX_EKSKUL_SMP55 = '802008';

    /**
     * Resolves the 6-digit prefix based on fee type and school unit.
     */
    public static function resolvePrefix(string $feeTypeCode, ?SchoolUnit $unit = null): string
    {
        $normalizedFee = mb_strtolower(trim($feeTypeCode));

        if (str_contains($normalizedFee, 'ekskul') || str_contains($normalizedFee, 'ekstrakurikuler')) {
            $unitCode = mb_strtoupper($unit?->code ?? '');
            $jenjang = mb_strtolower($unit?->jenjang_group ?? '');

            if ($unitCode === 'SMP-12') {
                return (string) config('services.billing_api.va_prefixes.ekskul_smp12', self::PREFIX_EKSKUL_SMP12);
            }
            if ($unitCode === 'SMP-55' || str_contains($unitCode, 'SMP')) {
                return (string) config('services.billing_api.va_prefixes.ekskul_smp55', self::PREFIX_EKSKUL_SMP55);
            }
            if ($jenjang === 'sd' || str_contains($unitCode, 'SD')) {
                return (string) config('services.billing_api.va_prefixes.ekskul_sd', self::PREFIX_EKSKUL_SD);
            }
            return (string) config('services.billing_api.va_prefixes.ekskul_tk', self::PREFIX_EKSKUL_TK);
        }

        if (str_contains($normalizedFee, 'jamiyyah')) {
            return (string) config('services.billing_api.va_prefixes.jamiyyah', self::PREFIX_JAMIYYAH);
        }

        if (str_contains($normalizedFee, 'pangkal')) {
            return (string) config('services.billing_api.va_prefixes.uang_pangkal', self::PREFIX_UANG_PANGKAL);
        }

        if (str_contains($normalizedFee, 'pendaftaran') || str_contains($normalizedFee, 'formulir')) {
            return (string) config('services.billing_api.va_prefixes.pendaftaran', self::PREFIX_PENDAFTARAN);
        }

        return (string) config('services.billing_api.va_prefixes.spp', self::PREFIX_SPP);
    }

    /**
     * Generates a full 16-digit Virtual Account number.
     * [Prefix 6-digit] + [Tahun Ajaran 4-digit] + [Nomor Siswa 6-digit] = 16 digits.
     */
    public static function generateVaNumber(Student $student, Bill|FeeType|string $billOrType): string
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

        $prefix = self::resolvePrefix($feeTypeCode, $student->schoolUnit);
        $academicYearCode = self::formatAcademicYearCode($academicYear ?: $student->entryYear?->year);
        $studentSeq = self::formatStudentCode($student);

        return $prefix . $academicYearCode . $studentSeq;
    }

    /**
     * Extracts 4-digit code from academic year (e.g. '2026/2027' -> '2627', '2027/2028' -> '2728').
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

        $currentYear = (int) date('y');

        return sprintf('%02d%02d', $currentYear, $currentYear + 1);
    }

    /**
     * Formats the student's identifier into a 6-digit numerical string for
     * the VA number.
     *
     * Deliberately the database id, not NIS or no_pendaftaran, even though
     * NIS is unique on its own. Both used to be parsed here - the trailing
     * six digits of NIS, or digits pulled out of a PMB registration number -
     * and either could collide: two different (genuinely unique) NIS values
     * sharing the same last six digits, or two different registration
     * numbers whose extracted digits happen to match. A collision here isn't
     * cosmetic - see BillingApiGateway - it means two different families'
     * transfers become indistinguishable by VA number. id is the one source
     * this table guarantees is unique and sequential; six digits holds every
     * student any single foundation will create for centuries.
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

    /**
     * Creates a VA bill on e-SPP (5.2.5).
     *
     * @param array<string, mixed> $mainForm
     * @param array<string, mixed> $bmi
     * @param array<string, mixed> $bsm
     * @return array<string, mixed>
     */
    public function createBilling(array $mainForm, array $bmi, array $bsm = []): array
    {
        $mainForm += ['bank_id' => (string) config('services.billing_api.bank_id', 1)];

        return $this->request('post', '/api/billing', [
            'main_form' => $mainForm,
            'bmi' => $bmi,
            'bsm' => $bsm,
        ]);
    }

    /**
     * Updates an existing VA bill on e-SPP (5.2.6).
     *
     * @param array<string, mixed> $mainForm
     * @param array<string, mixed> $bmi
     * @param array<string, mixed> $bsm
     * @return array<string, mixed>
     */
    public function updateBilling(string $uuid, array $mainForm, array $bmi = [], array $bsm = []): array
    {
        $mainForm += ['bank_id' => (string) config('services.billing_api.bank_id', 1)];

        return $this->request('put', '/api/billing/'.urlencode($uuid), [
            'main_form' => $mainForm,
            'bmi' => $bmi,
            'bsm' => $bsm,
        ]);
    }

    /**
     * Looks up a bill's status by its Virtual Account number (5.2.3).
     *
     * @return array<string, mixed>
     */
    public function getByVaNumber(string $nomorVa): array
    {
        return $this->request('get', '/api/billing/va/'.urlencode($nomorVa));
    }

    /**
     * Detail billing berdasarkan UUID (5.2.2).
     *
     * @return array<string, mixed>
     */
    public function getBillingByUuid(string $uuid): array
    {
        return $this->request('get', '/api/billing/'.urlencode($uuid));
    }

    /**
     * Mengambil seluruh data billing milik user login (5.2.4).
     *
     * @return array<string, mixed>
     */
    public function getAllBillings(int $page = 1, int $perPage = 50): array
    {
        return $this->request('get', "/api/billing/all-data?page={$page}&per_page={$perPage}");
    }

    /**
     * Obtains a valid Bearer access token, caching until close to expiration.
     */
    public function token(): string
    {
        $cached = Cache::get($this->tokenCacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl($this->baseUrl())
            ->timeout(10)
            ->asForm()
            ->post('/api/login', [
                'grant_type' => 'password',
                'client_id' => (string) config('services.billing_api.client_id'),
                'client_secret' => (string) config('services.billing_api.client_secret'),
                'username' => (string) config('services.billing_api.username'),
                'password' => (string) config('services.billing_api.password'),
            ]);

        if (! $response->successful()) {
            Log::error('Billing API login failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new BillingApiException(
                'Otentikasi Billing API gagal (HTTP '.$response->status().'). Periksa konfigurasi akun.',
                $response->status()
            );
        }

        $body = $response->json();
        $token = $body['access_token'] ?? null;
        $expiresIn = (int) ($body['expires_in'] ?? 3600);

        if (! is_string($token) || $token === '') {
            throw new BillingApiException('Respons login Billing API tidak mengandung access_token valid.', 500);
        }

        $ttl = max(60, $expiresIn - 300);
        Cache::put($this->tokenCacheKey(), $token, now()->addSeconds($ttl));

        return $token;
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $token = $this->token();

        try {
            $http = Http::baseUrl($this->baseUrl())
                ->withToken($token)
                ->acceptJson()
                ->timeout(15);

            /** @var Response $response */
            $response = match (strtolower($method)) {
                'get' => $http->get($path, $data),
                'post' => $http->post($path, $data),
                'put' => $http->put($path, $data),
                'delete' => $http->delete($path, $data),
                default => throw new BillingApiException("HTTP method {$method} tidak didukung.", 400),
            };
        } catch (ConnectionException $e) {
            Log::error('Billing API connection failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new BillingApiException('Tidak dapat terhubung ke server Billing API: '.$e->getMessage(), 503);
        }

        // On 401 Unauthorized, evict token and retry once
        if ($response->status() === 401) {
            Cache::forget($this->tokenCacheKey());
            $token = $this->token();

            $response = Http::baseUrl($this->baseUrl())
                ->withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->{$method}($path, $data);
        }

        if (! $response->successful()) {
            $body = $response->json() ?? [];
            $message = $body['message'] ?? 'Permintaan Billing API gagal';
            $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];

            Log::error('Billing API request error', [
                'path' => $path,
                'status' => $response->status(),
                'message' => $message,
                'errors' => $errors,
            ]);

            throw new BillingApiException($message, $response->status(), $errors);
        }

        $json = $response->json();

        return $json['data'] ?? $json;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.billing_api.base_url', 'http://43.225.66.150:8061'), '/');
    }

    private function tokenCacheKey(): string
    {
        return 'billing_api:access_token:'.md5((string) config('services.billing_api.username'));
    }
}
