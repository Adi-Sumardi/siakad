<?php

namespace App\Services\Notification;

use App\Models\NotificationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp through api-sendago.adilabs.id, the same gateway PMB uses.
 *
 *   POST {base_url}/api/messages
 *   Header: X-API-KEY: <key>
 *   Body:   {"to": "081234567890", "body": "..."}
 *
 * With credentials unset every send is logged instead of dispatched, so the
 * whole handoff runs end to end on a developer machine without a live gateway.
 */
class SendagoWhatsAppGateway implements WhatsAppGateway
{
    public function sendMessage(string $phone, string $message): NotificationResult
    {
        $baseUrl = config('services.sendago.base_url');
        $apiKey = config('services.sendago.api_key');

        $phone = PhoneNumberFormatter::toWhatsAppFormat($phone);

        if (! $phone) {
            return NotificationResult::fail('Nomor WhatsApp tidak valid.');
        }

        if (empty($baseUrl) || empty($apiKey)) {
            Log::info('[SendagoWhatsAppGateway] Credentials not configured, logging instead of sending.', [
                // Masked, and no message body (audit 2026-10-05): free-text
                // messages can carry names and amounts; the log row in
                // notification_logs is the encrypted record that matters.
                'phone' => NotificationLog::maskRecipient($phone),
            ]);

            return NotificationResult::ok(['mode' => 'log-only']);
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-API-KEY' => $apiKey])
                ->post(rtrim($baseUrl, '/').'/api/messages', [
                    'to' => $phone,
                    'body' => $message,
                ]);

            if ($response->failed()) {
                Log::warning('[SendagoWhatsAppGateway] Send failed', ['phone' => NotificationLog::maskRecipient($phone), 'error' => $response->body()]);

                return NotificationResult::fail($response->body());
            }

            return NotificationResult::ok($response->json() ?: []);
        } catch (\Throwable $e) {
            Log::warning('[SendagoWhatsAppGateway] Send failed', ['phone' => NotificationLog::maskRecipient($phone), 'error' => $e->getMessage()]);

            return NotificationResult::fail($e->getMessage());
        }
    }
}
