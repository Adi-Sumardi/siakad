<?php

use App\Http\Controllers\Api\Webhooks\BillingApiWebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// e-SPP Billing API "Callback Keluar" (Bank Muamalat BMI Virtual Account)
Route::post('/api/payment-webhook/{uuid}', [BillingApiWebhookController::class, 'handle'])
    ->name('billing-api.webhook')
    ->withoutMiddleware([VerifyCsrfToken::class]);

