<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\InvitationController;
use App\Http\Controllers\Api\Auth\OtpController;
use App\Http\Controllers\Api\Wali\BillController as WaliBillController;
use App\Http\Controllers\Api\Wali\DashboardController as WaliDashboardController;
use App\Http\Controllers\Api\Webhooks\PmbHandoffController;
use App\Http\Controllers\Api\Webhooks\XenditController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| JSON API for the Next.js frontend. Auth is Sanctum SPA cookie/session, the
| same as PMB: the frontend calls GET /sanctum/csrf-cookie once, then these
| routes authenticate through the session guard - no bearer token is ever
| stored in the browser.
|
*/

// Machine to machine. Outside auth:sanctum on purpose - PMB has no session,
// it proves itself with an HMAC signature over the raw body instead.
Route::post('/webhooks/pmb/students', [PmbHandoffController::class, 'store'])
    ->middleware('pmb.signature');

// Settles money, so it verifies its own token and records every delivery before
// acting - see the controller.
Route::post('/webhooks/xendit', [XenditController::class, 'handle']);

Route::prefix('auth')->group(function () {
    // Guardians: passwordless. The identifier decides the channel - an email
    // gets an emailed code, a phone number gets one over WhatsApp.
    Route::post('/otp/request', [OtpController::class, 'request'])->middleware('throttle:10,1');
    Route::post('/otp/verify', [OtpController::class, 'verify'])->middleware('throttle:20,1');

    // Staff only; a guardian account has no password to check.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// Activation runs unauthenticated by definition: the token is the credential.
Route::prefix('invitations')->middleware('throttle:20,1')->group(function () {
    Route::get('/{token}', [InvitationController::class, 'show']);
    Route::post('/{token}/activate', [InvitationController::class, 'activate']);
});

Route::middleware(['auth:sanctum', 'role:orangtua'])->prefix('wali')->group(function () {
    Route::get('/students', [WaliDashboardController::class, 'index']);

    Route::get('/bills', [WaliBillController::class, 'index']);
    Route::get('/bills/{ulid}', [WaliBillController::class, 'show']);
    // One invoice for however many bills were ticked - the whole point of
    // payment_allocations.
    Route::post('/checkout', [WaliBillController::class, 'checkout'])->middleware('throttle:20,1');
    Route::get('/payments', [WaliBillController::class, 'payments']);
});
