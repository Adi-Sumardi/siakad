<?php

use App\Http\Controllers\Api\Webhooks\BillingApiWebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// e-SPP Billing API "Callback Keluar" (Bank Muamalat BMI Virtual Account).
// No signature or shared secret is available from e-SPP to verify this
// request came from them - see BillingApiWebhookController for how it
// compensates (a live lookup against e-SPP's own record before settling
// anything, failing closed rather than trusting the payload). Throttled
// here too: payment_number is deterministic from a student's NIS + fee
// type + year, which makes it guessable, not just unguessable-until-leaked.
Route::post('/api/payment-webhook/{uuid}', [BillingApiWebhookController::class, 'handle'])
    ->name('billing-api.webhook')
    ->middleware('throttle:30,1')
    ->withoutMiddleware([VerifyCsrfToken::class]);

