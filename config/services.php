<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // WhatsApp gateway, shared with PMB. Unset -> every send is logged instead
    // of dispatched, so the handoff runs end to end on a laptop.
    'sendago' => [
        'base_url' => env('SENDAGO_BASE_URL'),
        'api_key' => env('SENDAGO_API_KEY'),
        // Sendago is an unofficial gateway behind one connected number - see
        // App\Jobs\SendWhatsAppMessage. Messages/minute across the whole app,
        // not per recipient. Login OTP no longer shares this risk at all -
        // see 'qontak' below.
        'send_rate_per_minute' => env('WHATSAPP_SEND_RATE_PER_MINUTE', 60),
    ],

    // Mekari Qontak - the yayasan's own verified WhatsApp Cloud API line,
    // same account/WABA PMB uses (see PMB's config/services.php and
    // App\Services\Notification\QontakWhatsAppGateway for the full HMAC
    // contract notes). Login OTP only for now
    // (QontakWhatsAppGateway::sendOtp(), the 'otp_login' Authentication
    // template) - every other WhatsApp send in this app stays on Sendago
    // (App\Jobs\SendWhatsAppMessage), since an official Business line can
    // only ever send an approved template to a number that hasn't
    // messaged first, never free text.
    'qontak' => [
        'base_url' => env('QONTAK_BASE_URL', 'https://api.mekari.com/qontak/chat/v1'),
        // Signs every request (Mekari's own HMAC scheme, not a Bearer
        // token) - see QontakWhatsAppGateway::post(). No access/refresh
        // token to manage.
        'client_id' => env('QONTAK_CLIENT_ID'),
        'client_secret' => env('QONTAK_CLIENT_SECRET'),
        // GET {base_url}/../../open/v1/integrations?target_channel=wa (Bearer
        // auth there, a different scheme from the send endpoint above) - the
        // WhatsApp channel's own id inside the Qontak account, required on
        // every broadcast send alongside the template id. Same value as
        // PMB's, since it's the same WABA.
        'channel_integration_id' => env('QONTAK_CHANNEL_INTEGRATION_ID'),
        // UUID of the approved 'otp_login' Authentication template - reused
        // from PMB (same Qontak account/template, generic copy that never
        // names either app). Body has exactly one variable (the code
        // itself); the template's own copy-code button repeats it.
        'otp_template_id' => env('QONTAK_OTP_TEMPLATE_ID'),
        // UUID of the approved 'reminder_spp_school' Utility template (asks
        // for money) - SPP only for now (see
        // BillReminderSender::queueSppReminderTemplate()). 5 positional
        // variables: nama anak, bulan tagihan, jumlah, VA Muamalat, kode
        // bayar BSI (VA BSI minus its 4-digit institution code, 7895 for SPP).
        'spp_reminder_template_id' => env('QONTAK_SPP_REMINDER_TEMPLATE_ID'),
        // UUID of the approved 'receipt_spp_school' Utility template
        // (confirms money arrived) - see PaymentReceiptSender. 6 positional
        // variables: nama anak, bulan tagihan, jumlah dibayar, tanggal+jam
        // bayar, metode/bank, nomor referensi pembayaran.
        'spp_receipt_template_id' => env('QONTAK_SPP_RECEIPT_TEMPLATE_ID'),
    ],

    // Email gateway. Auth is memberId+secret in the request body, not a header.
    'sendagomail' => [
        'base_url' => env('SENDAGOMAIL_BASE_URL'),
        'member_id' => env('SENDAGOMAIL_MEMBER_ID'),
        'secret' => env('SENDAGOMAIL_SECRET'),
    ],

    // Shared secret PMB signs each handoff with. No default: an unset secret
    // makes the webhook refuse every request rather than accept unsigned ones.
    'pmb' => [
        'handoff_secret' => env('PMB_HANDOFF_SECRET'),
        'base_url' => env('PMB_BASE_URL'),
    ],

    // Web Service Billing API e-SPP (Bank Muamalat BMI & Bank Syariah Indonesia BSI Virtual Account).
    // Endpoint paths and payload shape verified against docs/Dokumentasi_Billing_API
    // (sections 5.1-5.3) - see BillingApiClient for what that verification changed.
    'billing_api' => [
        'base_url' => env('BILLING_API_BASE_URL', 'http://43.225.66.150:8061'),
        'client_id' => env('BILLING_API_CLIENT_ID', ''),
        'client_secret' => env('BILLING_API_CLIENT_SECRET', ''),
        'username' => env('BILLING_API_USERNAME', 'admin'),
        'password' => env('BILLING_API_PASSWORD', 'admin123'),
        // Fallback only - createBilling() callers always pass an explicit
        // per-bank bank_id (see banks.*.bank_id below); this is what a caller
        // that forgets to set one falls back to.
        'bank_id' => env('BILLING_API_BANK_ID', '1'),
        'va_due_days' => env('BILLING_API_VA_DUE_DAYS', 3),
        'va_admin_fee' => env('BILLING_API_ADMIN_FEE', 0),

        // One bill belongs to exactly one bank_id at e-SPP (main_form.bank_id
        // is singular) - each channel's own id, name, code, institution code,
        // and VA prefixes live together here so generateVaNumber() and
        // createInvoice() resolve everything about "which bank" from one key.
        'banks' => [
            // VA prefixes below were CONFIRMED correct by the school on
            // 2026-09-17 (Muamalat 8020.01-.08, BSI 3656.01-.08 - same layout,
            // different institution head). Fee types WITHOUT a prefix here
            // (seragam, buku, kegiatan) get NO VA at all: BillingApiClient
            // refuses rather than borrowing another fee type's prefix, so
            // those stay cash-at-the-desk until e-SPP registers one - add it
            // as an explicit 'va_prefixes.{fee_code}' key when they do.
            'muamalat' => [
                // Unconfirmed with e-SPP as of 2026-09 - defaults to '1', the
                // original single-bank value, which is likely Muamalat's real
                // id but has never been verified as such.
                'bank_id' => env('BILLING_API_BMI_BANK_ID', '1'),
                'bank_name' => 'Bank Muamalat',
                'bank_code' => '147',
                'institution_code' => env('BILLING_API_BMI_INSTITUTION_CODE', '8020'),
                'va_prefixes' => [
                    'spp' => env('BILLING_API_BMI_VA_PREFIX_SPP', '802001'),
                    'uang_pangkal' => env('BILLING_API_BMI_VA_PREFIX_UANG_PANGKAL', '802002'),
                    'jamiyyah' => env('BILLING_API_BMI_VA_PREFIX_JAMIYYAH', '802003'),
                    'pendaftaran' => env('BILLING_API_BMI_VA_PREFIX_PENDAFTARAN', '802004'),
                    'ekskul_tk' => env('BILLING_API_BMI_VA_PREFIX_EKSKUL_TK', '802005'),
                    'ekskul_sd' => env('BILLING_API_BMI_VA_PREFIX_EKSKUL_SD', '802006'),
                    'ekskul_smp12' => env('BILLING_API_BMI_VA_PREFIX_EKSKUL_SMP12', '802007'),
                    'ekskul_smp55' => env('BILLING_API_BMI_VA_PREFIX_EKSKUL_SMP55', '802008'),
                    'cambridge_sd' => env('BILLING_API_BMI_VA_PREFIX_CAMBRIDGE_SD', '802009'),
                    'cambridge_smp12' => env('BILLING_API_BMI_VA_PREFIX_CAMBRIDGE_SMP12', '802010'),
                    'cambridge_smp55' => env('BILLING_API_BMI_VA_PREFIX_CAMBRIDGE_SMP55', '802011'),
                ],
            ],
            'bsi' => [
                // Unconfirmed with e-SPP as of 2026-09 - also defaults to '1',
                // which almost certainly is NOT BSI's real bank_id (that
                // default was never anything but Muamalat's). A BSI VA is
                // unlikely to be genuinely payable until e-SPP gives the real
                // value.
                'bank_id' => env('BILLING_API_BSI_BANK_ID', '1'),
                'bank_name' => 'Bank Syariah Indonesia (BSI)',
                'bank_code' => '451',
                'institution_code' => env('BILLING_API_BSI_INSTITUTION_CODE', '7895'),
                // BSI splits by institution code (school, 2026-09-24): 3656
                // is uang pangkal ONLY, 7895 is SPP and everything else.
                // Uang pangkal and pendaftaran are billed by PMB (see
                // BillingApiClient::PMB_OWNED_FEE_TYPES), which keeps 3656
                // for both - their rows stay here only so the lookup table
                // matches e-SPP's code list.
                'va_prefixes' => [
                    'spp' => env('BILLING_API_BSI_VA_PREFIX_SPP', '789501'),
                    'uang_pangkal' => env('BILLING_API_BSI_VA_PREFIX_UANG_PANGKAL', '365602'),
                    'jamiyyah' => env('BILLING_API_BSI_VA_PREFIX_JAMIYYAH', '789503'),
                    'pendaftaran' => env('BILLING_API_BSI_VA_PREFIX_PENDAFTARAN', '365604'),
                    'ekskul_tk' => env('BILLING_API_BSI_VA_PREFIX_EKSKUL_TK', '789505'),
                    'ekskul_sd' => env('BILLING_API_BSI_VA_PREFIX_EKSKUL_SD', '789506'),
                    'ekskul_smp12' => env('BILLING_API_BSI_VA_PREFIX_EKSKUL_SMP12', '789507'),
                    'ekskul_smp55' => env('BILLING_API_BSI_VA_PREFIX_EKSKUL_SMP55', '789508'),
                    'cambridge_sd' => env('BILLING_API_BSI_VA_PREFIX_CAMBRIDGE_SD', '789509'),
                    'cambridge_smp12' => env('BILLING_API_BSI_VA_PREFIX_CAMBRIDGE_SMP12', '789510'),
                    'cambridge_smp55' => env('BILLING_API_BSI_VA_PREFIX_CAMBRIDGE_SMP55', '789511'),
                ],
            ],
        ],
    ],

];
