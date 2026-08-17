<?php

use App\Http\Controllers\Api\Admin\BillController as AdminBillController;
use App\Http\Controllers\Api\Admin\BillingRunController;
use App\Http\Controllers\Api\Admin\FeeSettingController;
use App\Http\Controllers\Api\Admin\ReportController;
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

/*
 * Staff area. `role:` decides who may reach an endpoint; which rows they see is
 * a separate question answered by visibleTo() on each model - a role check
 * alone would let one unit's admin open another unit's student.
 */
Route::middleware(['auth:sanctum', 'role:admin,admin_unit'])->prefix('admin')->group(function () {
    Route::get('/bills', [AdminBillController::class, 'index']);
    Route::post('/bills/{ulid}/waive', [AdminBillController::class, 'waive']);
    Route::post('/bills/{ulid}/cancel', [AdminBillController::class, 'cancel']);
    Route::post('/bills/{ulid}/payments', [AdminBillController::class, 'recordPayment']);

    Route::get('/payments/pending', [AdminBillController::class, 'pendingVerification']);
    Route::post('/payments/{ulid}/verify', [AdminBillController::class, 'verifyPayment']);
    Route::post('/payments/{ulid}/reject', [AdminBillController::class, 'rejectPayment']);

    // A per-unit admin runs billing for their own unit - the controller forces
    // the unit rather than trusting the parameter.
    Route::get('/billing-runs', [BillingRunController::class, 'index']);
    Route::post('/billing-runs/preview', [BillingRunController::class, 'preview']);
    Route::post('/billing-runs', [BillingRunController::class, 'store']);

    Route::get('/reports/receivables', [ReportController::class, 'receivables']);
    Route::get('/reports/collections', [ReportController::class, 'collections']);
});

/*
 * Prices are central-admin only, the same split PMB draws around its settings:
 * a per-unit admin bills their unit but does not decide what it charges.
 */
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/fee-types', [FeeSettingController::class, 'types']);
    Route::post('/fee-types', [FeeSettingController::class, 'storeType']);
    Route::patch('/fee-types/{feeType}', [FeeSettingController::class, 'updateType']);

    Route::get('/fee-rates', [FeeSettingController::class, 'rates']);
    Route::post('/fee-rates', [FeeSettingController::class, 'storeRate']);
    Route::patch('/fee-rates/{feeRate}', [FeeSettingController::class, 'updateRate']);
});
