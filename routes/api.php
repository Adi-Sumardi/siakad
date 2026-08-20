<?php

use App\Http\Controllers\Api\Admin\AchievementController as AdminAchievementController;
use App\Http\Controllers\Api\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Api\Admin\BillController as AdminBillController;
use App\Http\Controllers\Api\Admin\BillingRunController;
use App\Http\Controllers\Api\Admin\FeeSettingController;
use App\Http\Controllers\Api\Admin\PointController as AdminPointController;
use App\Http\Controllers\Api\Admin\PointRuleController;
use App\Http\Controllers\Api\Admin\PointThresholdController;
use App\Http\Controllers\Api\Admin\ReferenceController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\InvitationController;
use App\Http\Controllers\Api\Auth\OtpController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\Guru\AchievementController as GuruAchievementController;
use App\Http\Controllers\Api\Guru\ClassroomController as GuruClassroomController;
use App\Http\Controllers\Api\Guru\PointController as GuruPointController;
use App\Http\Controllers\Api\Wali\AchievementController as WaliAchievementController;
use App\Http\Controllers\Api\Wali\AnnouncementController as WaliAnnouncementController;
use App\Http\Controllers\Api\Wali\BillController as WaliBillController;
use App\Http\Controllers\Api\Wali\DashboardController as WaliDashboardController;
use App\Http\Controllers\Api\Wali\PointController as WaliPointController;
use App\Http\Controllers\Api\Webhooks\PmbHandoffController;
use App\Http\Controllers\Api\Webhooks\SendagoPayController;
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
Route::post('/webhooks/sendagopay', [SendagoPayController::class, 'handle']);

Route::prefix('auth')->group(function () {
    // The only way in, for everyone. The identifier decides the channel: an
    // email gets an emailed code, a phone number gets one over WhatsApp. There
    // is no password endpoint because there are no passwords.
    Route::post('/otp/request', [OtpController::class, 'request'])->middleware('throttle:10,1');
    Route::post('/otp/verify', [OtpController::class, 'verify'])->middleware('throttle:20,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [SessionController::class, 'logout']);
        Route::get('/me', [SessionController::class, 'me']);
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
    Route::get('/bills/{ulid}/pdf', [WaliBillController::class, 'pdf']);
    // One invoice for however many bills were ticked - the whole point of
    // payment_allocations.
    Route::post('/checkout', [WaliBillController::class, 'checkout'])->middleware('throttle:20,1');
    Route::get('/payments', [WaliBillController::class, 'payments']);

    Route::get('/students/{ulid}/points', [WaliPointController::class, 'index']);
    Route::get('/students/{ulid}/achievements', [WaliAchievementController::class, 'index']);
    // A guardian's own account of a win - it waits for staff to confirm it,
    // and never carries points on its own.
    Route::post('/students/{ulid}/achievements', [WaliAchievementController::class, 'store']);

    Route::get('/announcements', [WaliAnnouncementController::class, 'index']);
});

/*
 * Homeroom and subject teachers. Student::scopeVisibleTo() already treats
 * `guru` as unit-scoped exactly like admin_unit - a teacher sees every student
 * in their unit, not only their own homeroom, because they teach across it.
 */
Route::middleware(['auth:sanctum', 'role:guru'])->prefix('guru')->group(function () {
    Route::get('/classrooms', [GuruClassroomController::class, 'index']);
    Route::get('/classrooms/{ulid}/students', [GuruClassroomController::class, 'students']);

    Route::get('/point-rules', [GuruPointController::class, 'rules']);
    Route::get('/students/{ulid}/points', [GuruPointController::class, 'studentLedger']);
    Route::post('/points', [GuruPointController::class, 'store']);
    // One rule, many students - a whole line late to assembly recorded once.
    Route::post('/points/bulk', [GuruPointController::class, 'storeBulk']);
    // Excludes a record from the balance; the row and its reasoning stay on
    // file - never a DELETE. See docs/01-ARSITEKTUR.md D6.
    Route::patch('/points/{ulid}/revoke', [GuruPointController::class, 'revoke']);

    // Trusted immediately, unlike a guardian's own submission of the same thing.
    Route::post('/achievements', [GuruAchievementController::class, 'store']);
});

/*
 * Private files - certificates, activity photos, point evidence, announcement
 * attachments. One gate for all four: whoever is asking must be able to see
 * the row that owns the file, via the same visibleTo() scope that already
 * governs its JSON. No role restriction beyond being signed in - the
 * ownership check does the actual work.
 */
Route::middleware('auth:sanctum')->prefix('files')->group(function () {
    Route::get('/achievements/{ulid}/sertifikat', [FileController::class, 'achievementSertifikat']);
    Route::get('/achievements/{ulid}/foto', [FileController::class, 'achievementFoto']);
    Route::get('/points/{ulid}/evidence', [FileController::class, 'pointEvidence']);
    Route::get('/announcements/{ulid}/file', [FileController::class, 'announcementFile']);
});

/*
 * Staff area. `role:` decides who may reach an endpoint; which rows they see is
 * a separate question answered by visibleTo() on each model - a role check
 * alone would let one unit's admin open another unit's student.
 */
Route::middleware(['auth:sanctum', 'role:admin,admin_unit'])->prefix('admin')->group(function () {
    Route::get('/bills', [AdminBillController::class, 'index']);
    Route::get('/bills/{ulid}/pdf', [AdminBillController::class, 'pdf']);
    Route::post('/bills/{ulid}/waive', [AdminBillController::class, 'waive']);
    Route::post('/bills/{ulid}/cancel', [AdminBillController::class, 'cancel']);
    Route::post('/bills/{ulid}/payments', [AdminBillController::class, 'recordPayment']);

    // No manual verification endpoints: Xendit's callback settles online
    // payments on its own, and cash at the front desk is settled the moment the
    // admin records it. Nothing arrives here needing a human to approve it.

    // A per-unit admin runs billing for their own unit - the controller forces
    // the unit rather than trusting the parameter.
    Route::get('/billing-runs', [BillingRunController::class, 'index']);
    Route::post('/billing-runs/preview', [BillingRunController::class, 'preview']);
    Route::post('/billing-runs', [BillingRunController::class, 'store']);

    Route::get('/reports/receivables', [ReportController::class, 'receivables']);
    Route::get('/reports/collections', [ReportController::class, 'collections']);

    // A per-unit admin manages their own unit's rules/thresholds and never a
    // school-wide one - PointRuleController and PointThresholdController
    // enforce this internally, the same way BillingRunController forces a
    // unit rather than trusting the request.
    Route::get('/point-rules', [PointRuleController::class, 'index']);
    Route::post('/point-rules', [PointRuleController::class, 'store']);
    Route::patch('/point-rules/{pointRule}', [PointRuleController::class, 'update']);
    Route::delete('/point-rules/{pointRule}', [PointRuleController::class, 'destroy']);

    Route::get('/point-thresholds', [PointThresholdController::class, 'index']);
    Route::post('/point-thresholds', [PointThresholdController::class, 'store']);
    Route::patch('/point-thresholds/{pointThreshold}', [PointThresholdController::class, 'update']);

    Route::get('/points', [AdminPointController::class, 'index']);

    Route::get('/achievements', [AdminAchievementController::class, 'index']);
    Route::post('/achievements/{ulid}/verify', [AdminAchievementController::class, 'verify']);
    Route::post('/achievements/{ulid}/reject', [AdminAchievementController::class, 'reject']);

    Route::get('/announcements', [AdminAnnouncementController::class, 'index']);
    Route::post('/announcements', [AdminAnnouncementController::class, 'store']);
    Route::patch('/announcements/{ulid}', [AdminAnnouncementController::class, 'update']);
    Route::delete('/announcements/{ulid}', [AdminAnnouncementController::class, 'destroy']);

    // Reading the catalogue is open to both admin kinds - a per-unit admin has
    // to know what fee types and rates exist before running billing for their
    // own unit. FeeSettingController::rates() scopes the response to their
    // unit regardless of what was asked for.
    Route::get('/fee-types', [FeeSettingController::class, 'types']);
    Route::get('/fee-rates', [FeeSettingController::class, 'rates']);
    Route::get('/discount-schemes', [\App\Http\Controllers\Api\Admin\DiscountController::class, 'schemes']);
    Route::get('/student-discounts', [\App\Http\Controllers\Api\Admin\DiscountController::class, 'studentDiscounts']);

    // Pickers every admin form needs - none of it sensitive, so one response
    // shape for both admin kinds.
    Route::get('/school-units', [ReferenceController::class, 'schoolUnits']);
    Route::get('/academic-years', [ReferenceController::class, 'academicYears']);
    Route::get('/classrooms', [ReferenceController::class, 'classrooms']);
});

/*
 * Setting prices & discounts is central-admin only, the same split PMB draws around its
 * settings: a per-unit admin bills their unit but does not decide what it charges.
 */
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::post('/fee-types', [FeeSettingController::class, 'storeType']);
    Route::patch('/fee-types/{feeType}', [FeeSettingController::class, 'updateType']);

    Route::post('/fee-rates', [FeeSettingController::class, 'storeRate']);
    Route::patch('/fee-rates/{feeRate}', [FeeSettingController::class, 'updateRate']);

    Route::post('/discount-schemes', [\App\Http\Controllers\Api\Admin\DiscountController::class, 'storeScheme']);
    Route::patch('/discount-schemes/{discountScheme}', [\App\Http\Controllers\Api\Admin\DiscountController::class, 'updateScheme']);
    Route::delete('/discount-schemes/{discountScheme}', [\App\Http\Controllers\Api\Admin\DiscountController::class, 'destroyScheme']);

    Route::post('/student-discounts', [\App\Http\Controllers\Api\Admin\DiscountController::class, 'assignStudentDiscount']);
    Route::delete('/student-discounts/{studentDiscount}', [\App\Http\Controllers\Api\Admin\DiscountController::class, 'revokeStudentDiscount']);
});
