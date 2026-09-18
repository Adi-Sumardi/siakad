<?php

namespace App\Services\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Email through sendagomail.adilabs.id, the gateway PMB already sends from.
 *
 * The provider does no templating and takes no API-key header: auth is
 * memberId+secret in the JSON body, and the caller must supply a fully
 * rendered subject and body.
 *
 *   POST {base_url}/emails/api-send
 *   Body: {"memberId": "...", "secret": "...", "toAddr": "...", "subject": "...", "body": "..."}
 *
 * Call sites keep the {$template, $data} shape for readability; render() below
 * is the one place that turns it into the text Sendago wants.
 */
class SendagoMailGateway implements MailGateway
{
    /** Sendago rejects more than this many attachments per message. */
    private const MAX_ATTACHMENTS = 10;

    public function send(string $to, string $template, array $data, array $attachments = []): NotificationResult
    {
        $baseUrl = config('services.sendagomail.base_url');
        $memberId = config('services.sendagomail.member_id');
        $secret = config('services.sendagomail.secret');

        if (empty($baseUrl) || empty($memberId) || empty($secret)) {
            Log::info('[SendagoMailGateway] Credentials not configured, logging email instead of sending.', [
                'to' => $to,
                'template' => $template,
                'data' => $data,
            ]);

            return NotificationResult::ok(['mode' => 'log-only']);
        }

        [$subject, $body] = $this->render($template, $data);

        $payload = [
            'memberId' => $memberId,
            'secret' => $secret,
            'toAddr' => $to,
            'subject' => $subject,
            'body' => $body,
        ];

        if (! empty($attachments)) {
            $payload['attachments'] = array_map(fn (array $a) => [
                'filename' => $a['filename'],
                'contentBase64' => base64_encode($a['content']),
            ], array_slice($attachments, 0, self::MAX_ATTACHMENTS));
        }

        try {
            $response = Http::timeout(30)->post(rtrim($baseUrl, '/').'/emails/api-send', $payload);

            if ($response->failed()) {
                Log::warning('[SendagoMailGateway] Send failed', [
                    'to' => $to,
                    'template' => $template,
                    'error' => $response->body(),
                ]);

                return NotificationResult::fail($response->body());
            }

            return NotificationResult::ok($response->json() ?: []);
        } catch (\Throwable $e) {
            Log::warning('[SendagoMailGateway] Send failed', ['to' => $to, 'template' => $template, 'error' => $e->getMessage()]);

            return NotificationResult::fail($e->getMessage());
        }
    }

    /**
     * @return array{0: string, 1: string} [$subject, $body]
     */
    private function render(string $template, array $data): array
    {
        return match ($template) {
            /**
             * The invitation. Deliberately leads with the paid uang pangkal:
             * that is the event the family just lived through, and naming it
             * is what makes the email read as a continuation of PMB rather
             * than an unexplained message from a system they never signed up
             * for. No password appears anywhere - there are none in this app.
             */
            'school_account_invite' => [
                "Akun Siakad untuk {$data['nama_panggilan']} sudah siap",
                "Yth. {$data['guardian_name']},\n\n"
                    ."Uang pangkal {$data['student_name']} sudah kami terima lunas. "
                    ."{$data['nama_panggilan']} resmi tercatat sebagai siswa {$data['unit_label']} "
                    ."tahun ajaran {$data['academic_year']}.\n\n"
                    ."Mulai sekarang tagihan sekolah, catatan poin, dan prestasi {$data['nama_panggilan']} "
                    ."dapat dilihat melalui aplikasi sekolah.\n\n"
                    ."Silakan masuk melalui tautan berikut:\n\n"
                    ."{$data['activation_url']}\n\n"
                    ."Akun Anda: {$data['login_identifier']}\n"
                    ."Tidak ada kata sandi yang perlu dibuat - setiap kali masuk, kami kirimkan "
                    ."kode sekali pakai ke alamat ini.\n\n"
                    ."Tautan di atas berlaku sampai {$data['expires_at']}. Lewat dari itu pun Anda "
                    ."tetap bisa masuk lewat kode.\n\n"
                    .'Terima kasih.',
            ],
            /**
             * The sign-in code. Short, and it says the one thing that matters
             * for this kind of message: nobody legitimate will ever ask for it.
             */
            'login_otp' => [
                "Kode masuk Siakad YAPI: {$data['code']}",
                "Yth. {$data['name']},\n\n"
                    ."Kode untuk masuk ke aplikasi sekolah:\n\n"
                    ."    {$data['code']}\n\n"
                    ."Kode berlaku {$data['minutes']} menit dan hanya bisa dipakai satu kali.\n\n"
                    ."Jangan berikan kode ini kepada siapa pun, termasuk yang mengaku petugas sekolah. "
                    .'Jika Anda tidak sedang mencoba masuk, abaikan email ini.',
            ],
            /**
             * Due-date nudge. Leads with the amount and the date because that
             * is the whole content; a parent scanning a notification bar should
             * not have to open it to learn what is owed and by when.
             */
            'bill_reminder' => [
                match ($data['kind']) {
                    'h7' => "Pengingat: {$data['description']} - {$data['student_name']}",
                    'h1' => "Besok jatuh tempo: {$data['description']} - {$data['student_name']}",
                    default => "Lewat jatuh tempo: {$data['description']} - {$data['student_name']}",
                },
                "Yth. {$data['guardian_name']},\n\n"
                    .match ($data['kind']) {
                        'h7' => "{$data['description']} untuk {$data['student_name']} akan jatuh tempo pada {$data['due_date']}.",
                        'h1' => "{$data['description']} untuk {$data['student_name']} jatuh tempo besok, {$data['due_date']}.",
                        default => "{$data['description']} untuk {$data['student_name']} sudah lewat jatuh tempo ({$data['due_date']}).",
                    }."\n\n"
                    ."Sisa tagihan : Rp {$data['amount']}\n\n"
                    ."Pembayaran dapat dilakukan melalui aplikasi sekolah.\n\n"
                    .'Abaikan email ini bila pembayaran sudah dilakukan.',
            ],
            default => [
                'Notifikasi Siakad YAPI',
                implode("\n", array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($data), $data)),
            ],
        };
    }
}
