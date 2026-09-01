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

    // Gateway Pembayaran SendagoPay
    'sendagopay' => [
        'public_key' => env('SENDAGOPAY_PUBLIC_KEY'),
        'secret_key' => env('SENDAGOPAY_SECRET_KEY'),
        'webhook_secret' => env('SENDAGOPAY_WEBHOOK_SECRET'),
        'base_url' => env('SENDAGOPAY_BASE_URL', 'https://api-sendagopay.adilabs.id'),
    ],

    // Payment gateway Xendit. Left unset, checkout still works end to end but produces
    // no invoice URL - a laptop must not be able to mint payable invoices.
    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
        'base_url' => env('XENDIT_BASE_URL', 'https://api.xendit.co'),
        'invoice_duration' => env('XENDIT_INVOICE_DURATION', 86400),
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
                'institution_code' => env('BILLING_API_BSI_INSTITUTION_CODE', '3656'),
                'va_prefixes' => [
                    'spp' => env('BILLING_API_BSI_VA_PREFIX_SPP', '365601'),
                    'uang_pangkal' => env('BILLING_API_BSI_VA_PREFIX_UANG_PANGKAL', '365602'),
                    'jamiyyah' => env('BILLING_API_BSI_VA_PREFIX_JAMIYYAH', '365603'),
                    'pendaftaran' => env('BILLING_API_BSI_VA_PREFIX_PENDAFTARAN', '365604'),
                    'ekskul_tk' => env('BILLING_API_BSI_VA_PREFIX_EKSKUL_TK', '365605'),
                    'ekskul_sd' => env('BILLING_API_BSI_VA_PREFIX_EKSKUL_SD', '365606'),
                    'ekskul_smp12' => env('BILLING_API_BSI_VA_PREFIX_EKSKUL_SMP12', '365607'),
                    'ekskul_smp55' => env('BILLING_API_BSI_VA_PREFIX_EKSKUL_SMP55', '365608'),
                ],
            ],
        ],
    ],

];
