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

    public const PREFIX_CAMBRIDGE_SD = '802009';

    public const PREFIX_CAMBRIDGE_SMP12 = '802010';

    public const PREFIX_CAMBRIDGE_SMP55 = '802011';

    // Default Virtual Account 6-digit Prefixes for Bank Syariah Indonesia (BSI - Kode Bank 451).
    // Institution 3656 is uang pangkal only; 7895 is SPP and every other fee.
    public const PREFIX_BSI_SPP = '789501';

    public const PREFIX_BSI_UANG_PANGKAL = '365602';

    public const PREFIX_BSI_JAMIYYAH = '789503';

    public const PREFIX_BSI_PENDAFTARAN = '365604';

    public const PREFIX_BSI_EKSKUL_TK = '789505';

    public const PREFIX_BSI_EKSKUL_SD = '789506';

    public const PREFIX_BSI_EKSKUL_SMP12 = '789507';

    public const PREFIX_BSI_EKSKUL_SMP55 = '789508';

    public const PREFIX_BSI_CAMBRIDGE_SD = '789509';

    public const PREFIX_BSI_CAMBRIDGE_SMP12 = '789510';

    public const PREFIX_BSI_CAMBRIDGE_SMP55 = '789511';

    private const TOKEN_CACHE_KEY = 'billing_api:access_token';

    private const TOKEN_EXPIRY_BUFFER_SECONDS = 300;

    /**
     * Resolves the 6-digit VA prefix based on fee type code, school unit, and
     * bank - or null when e-SPP has no prefix registered for that fee type.
     *
     * Null is the honest answer: the old fallback silently reused the SPP
     * prefix for unmapped fee types (seragam, buku), minting a VA
     * identical to the student's SPP VA for the same year - one payment
     * could then settle the other's bill at the bank. An explicit
     * config key (va_prefixes.{fee_code}) is the escape hatch once e-SPP
     * confirms a real prefix.
     */
    /**
     * Fee-type fragments whose e-SPP VA ranges PMB issues; Siakad never
     * mints them. Fragments, not exact codes (audit T38-c): the fallback
     * mapping in resolvePrefix() matches by str_contains, so a code like
     * "uang-pangkal", "biaya_pendaftaran" or a plain "formulir" slipped
     * straight past the old exact-match list and minted a VA inside PMB's
     * live ranges.
     */
    public const PMB_OWNED_FEE_FRAGMENTS = ['pangkal', 'pendaftaran', 'formulir', 'registr'];

    public static function resolvePrefix(string $feeTypeCode, ?SchoolUnit $unit = null, string $bank = 'muamalat'): ?string
    {
        $normalizedFee = strtolower($feeTypeCode);
        $bankKey = strtolower($bank) === 'bsi' ? 'bsi' : 'muamalat';

        // These VA ranges are PMB's: the same e-SPP account serves both apps,
        // and a VA is prefix + year + the app's own student id - two id
        // spaces. A Siakad bill under PMB's prefix could mint the very number
        // a different PMB child is paying uang pangkal / formulir into.
        // Substring refusal so no admin-invented spelling of the same fee
        // can sneak under PMB's prefixes (audit T38-c).
        foreach (self::PMB_OWNED_FEE_FRAGMENTS as $fragment) {
            if (str_contains($normalizedFee, $fragment)) {
                return null;
            }
        }

        $explicit = (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.{$normalizedFee}", '');
        if ($explicit !== '') {
            return $explicit;
        }

        if (str_contains($normalizedFee, 'ekskul')) {
            $unitCode = strtoupper((string) ($unit?->code ?? ''));

            return match (true) {
                str_contains($unitCode, 'TK') => (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.ekskul_tk", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_TK : self::PREFIX_EKSKUL_TK),
                str_contains($unitCode, 'SD') => (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.ekskul_sd", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_SD : self::PREFIX_EKSKUL_SD),
                str_contains($unitCode, 'SMP-12') || str_contains($unitCode, 'SMP12') => (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.ekskul_smp12", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_SMP12 : self::PREFIX_EKSKUL_SMP12),
                str_contains($unitCode, 'SMP-55') || str_contains($unitCode, 'SMP55') => (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.ekskul_smp55", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_SMP55 : self::PREFIX_EKSKUL_SMP55),
                // Unknown unit stays null (audit T61), mirroring cambridge
                // below: the old ekskul_sd default quietly minted RA/PG/SMA
                // ekskul VAs under the SD prefix - a number e-SPP associates
                // with a different unit's range. A missing unit still falls
                // back to the SD prefix so catalogue listings can show the
                // type as VA-capable.
                default => $unitCode === ''
                    ? (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.ekskul_sd", $bankKey === 'bsi' ? self::PREFIX_BSI_EKSKUL_SD : self::PREFIX_EKSKUL_SD)
                    : null,
            };
        }

        // Cambridge bills only SD and the two SMP units (school decision
        // 2026-09-22), so - unlike ekskul - an unmatched unit keeps null:
        // TK/RA/PG/SMA must never mint a Cambridge VA. A missing unit falls
        // back to the SD prefix so catalogue listings can show the type as
        // VA-capable.
        if (str_contains($normalizedFee, 'cambridge')) {
            $unitCode = strtoupper((string) ($unit?->code ?? ''));

            if ($unitCode === '') {
                return (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.cambridge_sd", $bankKey === 'bsi' ? self::PREFIX_BSI_CAMBRIDGE_SD : self::PREFIX_CAMBRIDGE_SD);
            }

            return match (true) {
                str_contains($unitCode, 'SMP-12') || str_contains($unitCode, 'SMP12') => (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.cambridge_smp12", $bankKey === 'bsi' ? self::PREFIX_BSI_CAMBRIDGE_SMP12 : self::PREFIX_CAMBRIDGE_SMP12),
                str_contains($unitCode, 'SMP-55') || str_contains($unitCode, 'SMP55') => (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.cambridge_smp55", $bankKey === 'bsi' ? self::PREFIX_BSI_CAMBRIDGE_SMP55 : self::PREFIX_CAMBRIDGE_SMP55),
                str_contains($unitCode, 'SD') => (string) config("services.billing_api.banks.{$bankKey}.va_prefixes.cambridge_sd", $bankKey === 'bsi' ? self::PREFIX_BSI_CAMBRIDGE_SD : self::PREFIX_CAMBRIDGE_SD),
                default => null,
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

        return null;
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

        if ($prefix === null) {
            throw new BillingApiException(
                "Jenis biaya '{$feeTypeCode}' belum punya prefix Virtual Account terdaftar di bank - nomor VA tidak boleh dibuat untuk jenis ini."
            );
        }

        $academicYearCode = self::formatAcademicYearCode($academicYear ?: $student->entryYear?->year);
        $studentSeq = self::formatStudentCode($student);

        return $prefix.$academicYearCode.$studentSeq;
    }

    /**
     * Extracts 4-digit code from academic year (e.g. '2027/2028' -> '2728', '2026/2027' -> '2627').
     */
    public static function formatAcademicYearCode(?string $academicYear): string
    {
        if ($academicYear && preg_match('/(\d{4})\/(\d{4})/', $academicYear, $m)) {
            return substr($m[1], 2, 2).substr($m[2], 2, 2);
        }

        if ($academicYear && preg_match('/(\d{2})\/(\d{2})/', $academicYear, $m)) {
            return $m[1].$m[2];
        }

        if ($academicYear && preg_match('/^\d{4}$/', $academicYear)) {
            return $academicYear;
        }

        try {
            $activeYear = AcademicYear::where('is_active', true)->value('year');
            if ($activeYear && preg_match('/(\d{4})\/(\d{4})/', $activeYear, $m)) {
                return substr($m[1], 2, 2).substr($m[2], 2, 2);
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

    /**
     * Sanitizes a bill description (va_desc/va_desc1) before it leaves us.
     *
     * e-SPP's own backend 500'd on this in PMB's production traffic
     * (2026-09-02): "iconv(): Detected an incomplete multibyte character in
     * input string". Their server, not ours - but va_desc is built from
     * decrypted student names and unit labels we do not otherwise
     * constrain, and unlike customer_name (sanitizeCustomerName() above)
     * nothing was scrubbing it before submission. iconv()'s //IGNORE here
     * drops whatever byte sequence would have tripped theirs, so a
     * malformed name can no longer take a real payment attempt down.
     */
    public static function sanitizeDescription(?string $text, int $maxLength = 255): string
    {
        if (! $text) {
            return '-';
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        $clean = $clean !== false ? $clean : preg_replace('/[^\x20-\x7E]/', '', $text);
        $clean = preg_replace('/[^\p{L}\p{N}\s\.\,\-\/\(\):]/u', ' ', $clean ?? '');
        $clean = preg_replace('/\s+/', ' ', trim($clean ?? ''));

        if (mb_strlen($clean) > $maxLength) {
            $clean = mb_substr($clean, 0, $maxLength);
        }

        return $clean ?: '-';
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
                'Failed to authenticate with e-SPP Billing API: '.$response->body(),
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
     * @param  array<string, mixed>  $mainForm  customer_name, va_desc, va_desc1, jumlah_tagihan, date_start, date_end, priority, pay_type, sekolah, kelas. bank_id falls back to config('services.billing_api.bank_id') when omitted.
     * @param  array<string, mixed>  $bmi  va_number, ref_number
     * @param  array<string, mixed>  $bsm  nomor_pembayaran, id_tagihan
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
                'e-SPP createBilling failed: '.$response->body(),
                $response->status()
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Updates an existing billing record on e-SPP by its uuid (docs 5.2.6).
     * Used to shrink a superseded VA's date_end to today when a guardian
     * switches bank or re-checks out - see BillingApiGateway::expireVa().
     *
     * @param  array<string, mixed>  $mainForm  any of createBilling()'s main_form fields; only what is passed is changed. bank_id falls back to config('services.billing_api.bank_id') when omitted.
     * @param  array<string, mixed>  $bmi
     * @param  array<string, mixed>  $bsm
     */
    public function updateBilling(string $uuid, array $mainForm, array $bmi = [], array $bsm = []): array
    {
        $mainForm += ['bank_id' => (string) config('services.billing_api.bank_id', '1')];

        $payload = [
            'main_form' => $mainForm,
            'bmi' => $bmi,
            'bsm' => $bsm,
        ];

        $response = $this->client()->put('/api/billing/'.urlencode($uuid), $payload);

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->client()->put('/api/billing/'.urlencode($uuid), $payload);
        }

        if ($response->failed()) {
            Log::error('[BillingApiClient] updateBilling failed', [
                'uuid' => $uuid,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new BillingApiException(
                'e-SPP updateBilling failed: '.$response->body(),
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
                "e-SPP getByVaNumber failed for VA {$vaNumber}: ".$response->body(),
                $response->status()
            );
        }

        return $response->json() ?? [];
    }
}
