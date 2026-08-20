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

];
