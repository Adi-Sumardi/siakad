<?php

namespace App\Services\Notification;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Mekari Qontak Omnichannel - the yayasan's own verified WhatsApp Cloud API
 * line, same account/WABA PMB uses (ported from PMB's identical class
 * 2026-09-22 once PMB confirmed it live - see that app's
 * mekari-qontak-integration-recipe notes for the full troubleshooting
 * history, in particular: auth is HMAC not Bearer, and the Qontak account
 * needs both an Admin AND an SPV user provisioned or every call 401s with
 * "Invalid credentials" regardless of how correct the signature is).
 *
 * Deliberately NOT a WhatsAppGateway - that interface's sendMessage() is
 * free-form text, which an official WhatsApp Business line simply cannot
 * send to a number that hasn't messaged the business first within 24h;
 * every cold outbound message here MUST be an approved template. So this
 * only exposes sendTemplate() (generic, for whichever approved template a
 * caller has a UUID for) and sendOtp() (the one call site wired up today -
 * SendOtpWhatsAppMessage).
 *
 * Request contract:
 *   POST {base_url}/broadcasts/whatsapp/direct
 *   Body: {to_number, to_name, message_template_id, channel_integration_id,
 *          language: {code}, parameters: {body: [{key,value,value_text}],
 *          buttons: [{index,type,value}]}}
 *
 * Auth is Mekari's own HMAC scheme, NOT a Bearer access_token (a
 * Chat-Panel docs page describing Bearer auth is for a different, legacy
 * host - the actual api.mekari.com endpoints sign every request instead):
 * signature = base64(HMAC-SHA256("date: {RFC7231 date}\n{METHOD} {PATH} HTTP/1.1", client_secret)),
 * sent as `Authorization: hmac username="{client_id}", algorithm="hmac-sha256",
 * headers="date request-line", signature="{signature}"` alongside a `Date` header
 * carrying that same RFC 7231 timestamp. No access/refresh token to manage at all.
 */
class QontakWhatsAppGateway
{
    public function sendOtp(string $phone, string $code): NotificationResult
    {
        $templateId = config('services.qontak.otp_template_id');

        if (empty($templateId)) {
            Log::warning('[QontakWhatsAppGateway] otp_template_id not configured, cannot send OTP.');

            return NotificationResult::fail('Template OTP Qontak belum dikonfigurasi.');
        }

        return $this->sendTemplate(
            phone: $phone,
            toName: 'Orang Tua/Wali',
            templateId: $templateId,
            bodyValues: [$code],
            buttonValues: [$code],
        );
    }

    /**
     * @param  string[]  $bodyValues  Positional - index 0 fills {{1}}, index 1 fills {{2}}, etc.
     * @param  string[]  $buttonValues  Positional, one per interactive button component on the template (copy-code, dynamic URL, ...).
     */
    public function sendTemplate(string $phone, string $toName, string $templateId, array $bodyValues, array $buttonValues = []): NotificationResult
    {
        // Mekari's to_number contract is the 62-prefixed form (audit T42-b):
        // the reminder/receipt lanes used to convert at their call sites
        // while the OTP lane passed the local 08xx form straight through -
        // two formats to one endpoint meant one of them could never be
        // delivered. The conversion lives HERE now, so no caller can
        // disagree; callers keep handing in whatever the app stores
        // (PhoneNumberFormatter's 08xx form).
        $phone = self::toQontakNumber($phone);

        $baseUrl = config('services.qontak.base_url');
        $channelIntegrationId = config('services.qontak.channel_integration_id');
        $clientId = config('services.qontak.client_id');
        $clientSecret = config('services.qontak.client_secret');

        if (empty($baseUrl) || empty($channelIntegrationId) || empty($clientId) || empty($clientSecret)) {
            Log::info('[QontakWhatsAppGateway] Credentials not configured, logging template send instead of sending.', [
                'phone' => $phone,
                'template_id' => $templateId,
            ]);

            return NotificationResult::ok(['mode' => 'log-only']);
        }

        $body = [
            'to_number' => $phone,
            'to_name' => $toName,
            'message_template_id' => $templateId,
            'channel_integration_id' => $channelIntegrationId,
            'language' => ['code' => 'id'],
            'parameters' => [
                'body' => collect($bodyValues)->values()->map(fn ($value, $i) => [
                    'key' => (string) ($i + 1),
                    'value' => 'var'.($i + 1),
                    'value_text' => (string) $value,
                ])->all(),
            ],
        ];

        if (! empty($buttonValues)) {
            $body['parameters']['buttons'] = collect($buttonValues)->values()->map(fn ($value, $i) => [
                'index' => (string) $i,
                'type' => 'url',
                'value' => (string) $value,
            ])->all();
        }

        $path = rtrim(parse_url($baseUrl, PHP_URL_PATH), '/').'/broadcasts/whatsapp/direct';

        return $this->post($baseUrl.'/broadcasts/whatsapp/direct', $path, $clientId, $clientSecret, $body, $phone, $templateId);
    }

    /** 08xxxxxxxxxx (the app's stored form) -> 62xxxxxxxxxx; already-62 passes through; bare digits get the country code. */
    public static function toQontakNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?: '';

        if (str_starts_with($digits, '62')) {
            return $digits;
        }

        return '62'.(str_starts_with($digits, '0') ? substr($digits, 1) : $digits);
    }

    private function post(string $url, string $path, string $clientId, string $clientSecret, array $body, string $phone, string $templateId): NotificationResult
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $requestLine = "POST {$path} HTTP/1.1";
        $stringToSign = "date: {$date}\n{$requestLine}";
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $clientSecret, true));
        $authorization = 'hmac username="'.$clientId.'", algorithm="hmac-sha256", headers="date request-line", signature="'.$signature.'"';

        try {
            $client = new Client(['timeout' => 15]);
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => $authorization,
                    'Date' => $date,
                ],
                'json' => $body,
            ]);

            return NotificationResult::ok(json_decode((string) $response->getBody(), true) ?: []);
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
            Log::warning('[QontakWhatsAppGateway] Send failed', [
                'phone' => $phone,
                'template_id' => $templateId,
                'error' => $responseBody,
            ]);

            return NotificationResult::fail($responseBody);
        }
    }
}
