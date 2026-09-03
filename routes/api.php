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
use App\Http\Controllers\Api\Guru\AttendanceSessionController as GuruAttendanceSessionController;
use App\Http\Controllers\Api\Guru\ClassroomController as GuruClassroomController;
use App\Http\Controllers\Api\Guru\GradeController as GuruGradeController;
use App\Http\Controllers\Api\Guru\PointController as GuruPointController;
use App\Http\Controllers\Api\Public\AttendancePresensiController;
use App\Http\Controllers\Api\Wali\AchievementController as WaliAchievementController;
use App\Http\Controllers\Api\Wali\AnnouncementController as WaliAnnouncementController;
use App\Http\Controllers\Api\Wali\AttendanceController as WaliAttendanceController;
use App\Http\Controllers\Api\Wali\BillController as WaliBillController;
use App\Http\Controllers\Api\Wali\DashboardController as WaliDashboardController;
use App\Http\Controllers\Api\Wali\FeeSelectionController as WaliFeeSelectionController;
use App\Http\Controllers\Api\Wali\GradeController as WaliGradeController;
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
Route::get('/login', fn () => response()->json(['message' => 'Unauthenticated.'], 401))->name('login');
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

// Student self-check-in for a lesson period. Unauthenticated by definition -
// students have no account in this app - the session's token is the
// credential, same shape as `invitations` above. Never exposes a classroom
// roster: every lookup is one NIS in, one name out.
// 300/min, not the usual 60 - this bucket is shared per-IP, and a whole
// classroom scanning in on the same school WiFi egress IP within the same
// minute (lookup + check-in per student) can easily clear 60.
Route::prefix('presensi')->middleware('throttle:300,1')->group(function () {
    Route::get('/{token}', [AttendancePresensiController::class, 'show']);
    Route::post('/{token}/lookup', [AttendancePresensiController::class, 'lookup']);
    Route::post('/{token}/check-in', [AttendancePresensiController::class, 'checkIn']);
});

Route::middleware(['auth:sanctum', 'role:orangtua'])->prefix('wali')->group(function () {
    Route::get('/students', [WaliDashboardController::class, 'index']);

    Route::get('/bills', [WaliBillController::class, 'index']);
    Route::get('/bills/{ulid}', [WaliBillController::class, 'show']);
    Route::get('/bills/{ulid}/pdf', [WaliBillController::class, 'pdf']);
    Route::post('/checkout', [WaliBillController::class, 'checkout'])->middleware('throttle:20,1');
    Route::get('/payments', [WaliBillController::class, 'payments']);

    Route::get('/students/{ulid}/points', [WaliPointController::class, 'index']);
    Route::get('/students/{ulid}/attendance', [WaliAttendanceController::class, 'index']);
    Route::get('/students/{ulid}/achievements', [WaliAchievementController::class, 'index']);
    // A guardian's own account of a win - it waits for staff to confirm it,
    // and never carries points on its own.
    Route::post('/students/{ulid}/achievements', [WaliAchievementController::class, 'store']);

    Route::get('/students/{ulid}/fee-selections', [WaliFeeSelectionController::class, 'index']);
    Route::post('/students/{ulid}/fee-selections', [WaliFeeSelectionController::class, 'store']);

    Route::get('/students/{ulid}/grades', [WaliGradeController::class, 'index']);
    Route::get('/students/{ulid}/rapor', [WaliGradeController::class, 'rapor']);

    Route::get('/students/{ulid}/extracurriculars', [\App\Http\Controllers\Api\Wali\ExtracurricularController::class, 'index']);

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

    Route::get('/classrooms/{ulid}/schedules/today', [GuruClassroomController::class, 'schedulesToday']);
    Route::post('/schedules/{ulid}/attendance-sessions', [GuruAttendanceSessionController::class, 'open']);
    Route::get('/attendance-sessions/{ulid}/roster', [GuruAttendanceSessionController::class, 'roster']);
    Route::patch('/attendance-sessions/{ulid}/records/{recordUlid}/revoke', [GuruAttendanceSessionController::class, 'revoke']);
    Route::post('/attendance-sessions/{ulid}/complete', [GuruAttendanceSessionController::class, 'complete']);

    Route::get('/my-subjects', [GuruGradeController::class, 'myAssignments']);
    Route::get('/classrooms/{classroomUlid}/subjects/{subjectUlid}/grades', [GuruGradeController::class, 'roster']);
    Route::post('/classrooms/{classroomUlid}/subjects/{subjectUlid}/grades', [GuruGradeController::class, 'store']);

    // A pembina manages only the activities they themselves supervise.
    Route::get('/my-extracurriculars', [\App\Http\Controllers\Api\Guru\ExtracurricularController::class, 'index']);
    Route::get('/extracurriculars/{ulid}/members', [\App\Http\Controllers\Api\Guru\ExtracurricularController::class, 'roster']);
    Route::post('/extracurriculars/{ulid}/members', [\App\Http\Controllers\Api\Guru\ExtracurricularController::class, 'assignStudent']);
    Route::delete('/extracurriculars/{ulid}/members/{memberUlid}', [\App\Http\Controllers\Api\Guru\ExtracurricularController::class, 'removeMember']);
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
    Route::get('/dashboard/billing-chart', [\App\Http\Controllers\Api\Admin\DashboardChartController::class, 'billingChart']);
    Route::get('/dashboard/achievements-chart', [\App\Http\Controllers\Api\Admin\DashboardChartController::class, 'achievementsChart']);
    Route::get('/students', [\App\Http\Controllers\Api\Admin\StudentController::class, 'index']);
    Route::get('/students/dapodik-export', [\App\Http\Controllers\Api\Admin\StudentController::class, 'exportDapodik']);
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
    Route::get('/reports/attendance', [\App\Http\Controllers\Api\Admin\AttendanceReportController::class, 'summary']);

    Route::get('/subjects', [\App\Http\Controllers\Api\Admin\SubjectController::class, 'index']);
    Route::post('/subjects', [\App\Http\Controllers\Api\Admin\SubjectController::class, 'store']);

    Route::get('/classrooms/{classroomUlid}/schedules', [\App\Http\Controllers\Api\Admin\ScheduleController::class, 'index']);
    Route::post('/classrooms/{classroomUlid}/schedules', [\App\Http\Controllers\Api\Admin\ScheduleController::class, 'store']);
    Route::patch('/classrooms/{classroomUlid}/schedules/{ulid}', [\App\Http\Controllers\Api\Admin\ScheduleController::class, 'update']);
    Route::delete('/classrooms/{classroomUlid}/schedules/{ulid}', [\App\Http\Controllers\Api\Admin\ScheduleController::class, 'destroy']);

    // Classroom management - the one prerequisite kenaikan kelas massal
    // needed and never had (read stays on ReferenceController::classrooms()
    // below, this only writes).
    Route::post('/classrooms', [\App\Http\Controllers\Api\Admin\ClassroomController::class, 'store']);
    Route::patch('/classrooms/{ulid}', [\App\Http\Controllers\Api\Admin\ClassroomController::class, 'update']);
    Route::delete('/classrooms/{ulid}', [\App\Http\Controllers\Api\Admin\ClassroomController::class, 'destroy']);

    // Kenaikan kelas massal: roster of one classroom -> candidate classrooms
    // in the next academic year -> execute the batch.
    Route::get('/classrooms/{classroomUlid}/promotion-roster', [\App\Http\Controllers\Api\Admin\PromotionController::class, 'roster']);
    Route::get('/classrooms/{classroomUlid}/promotion-targets', [\App\Http\Controllers\Api\Admin\PromotionController::class, 'targets']);
    Route::post('/classrooms/{classroomUlid}/promote', [\App\Http\Controllers\Api\Admin\PromotionController::class, 'store']);

    Route::get('/extracurriculars', [\App\Http\Controllers\Api\Admin\ExtracurricularController::class, 'index']);
    Route::post('/extracurriculars', [\App\Http\Controllers\Api\Admin\ExtracurricularController::class, 'store']);
    Route::patch('/extracurriculars/{ulid}', [\App\Http\Controllers\Api\Admin\ExtracurricularController::class, 'update']);
    Route::get('/extracurriculars/{ulid}/members', [\App\Http\Controllers\Api\Admin\ExtracurricularController::class, 'roster']);
    Route::post('/extracurriculars/{ulid}/members', [\App\Http\Controllers\Api\Admin\ExtracurricularController::class, 'assignStudent']);
    Route::delete('/extracurriculars/{ulid}/members/{memberUlid}', [\App\Http\Controllers\Api\Admin\ExtracurricularController::class, 'removeMember']);

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

    // User management (viewable by admins)
    Route::get('/users', [\App\Http\Controllers\Api\Admin\UserController::class, 'index']);

    // Pickers every admin form needs - none of it sensitive, so one response
    // shape for both admin kinds.
    Route::get('/school-units', [ReferenceController::class, 'schoolUnits']);
    Route::get('/academic-years', [ReferenceController::class, 'academicYears']);
    Route::get('/terms', [ReferenceController::class, 'terms']);
    Route::get('/classrooms', [ReferenceController::class, 'classrooms']);

    Route::get('/grades', [\App\Http\Controllers\Api\Admin\GradeController::class, 'index']);
    Route::get('/students/{ulid}/rapor', [\App\Http\Controllers\Api\Admin\GradeController::class, 'rapor']);
});

/*
 * Setting prices & discounts and full user CRUD is central-admin only.
 */
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::post('/fee-types', [FeeSettingController::class, 'storeType']);
    Route::patch('/fee-types/{feeType}', [FeeSettingController::class, 'updateType']);
    Route::delete('/fee-types/{feeType}', [FeeSettingController::class, 'destroyType']);

    Route::post('/fee-rates', [FeeSettingController::class, 'storeRate']);
    Route::patch('/fee-rates/{feeRate}', [FeeSettingController::class, 'updateRate']);
    Route::delete('/fee-rates/{feeRate}', [FeeSettingController::class, 'destroyRate']);

    Route::post('/discount-schemes', [\App\Http\Controllers\Api\Admin\DiscountController::class, 'storeScheme']);
    Route::patch('/discount-schemes/{discountScheme}', [\App\Http\Controllers\Api\Admin\DiscountController::class, 'updateScheme']);
    Route::delete('/discount-schemes/{discountScheme}', [\App\Http\Controllers\Api\Admin\DiscountController::class, 'destroyScheme']);

    Route::post('/student-discounts', [\App\Http\Controllers\Api\Admin\DiscountController::class, 'assignStudentDiscount']);
    Route::delete('/student-discounts/{studentDiscount}', [\App\Http\Controllers\Api\Admin\DiscountController::class, 'revokeStudentDiscount']);

    Route::post('/users', [\App\Http\Controllers\Api\Admin\UserController::class, 'store']);
    Route::patch('/users/{user}', [\App\Http\Controllers\Api\Admin\UserController::class, 'update']);
    Route::delete('/users/{user}', [\App\Http\Controllers\Api\Admin\UserController::class, 'destroy']);

    Route::patch('/students/{student}', [\App\Http\Controllers\Api\Admin\StudentController::class, 'update']);
    Route::delete('/students/{student}', [\App\Http\Controllers\Api\Admin\StudentController::class, 'destroy']);

    Route::get('/school-units/manage', [\App\Http\Controllers\Api\Admin\SchoolUnitController::class, 'index']);
    Route::post('/school-units', [\App\Http\Controllers\Api\Admin\SchoolUnitController::class, 'store']);
    Route::patch('/school-units/{schoolUnit}', [\App\Http\Controllers\Api\Admin\SchoolUnitController::class, 'update']);
    Route::delete('/school-units/{schoolUnit}', [\App\Http\Controllers\Api\Admin\SchoolUnitController::class, 'destroy']);

    // Academic year management
    Route::post('/academic-years', [ReferenceController::class, 'storeAcademicYear']);
    Route::post('/academic-years/{academicYear}/activate', [ReferenceController::class, 'activateAcademicYear']);

    // Bulk Import endpoints
    Route::post('/import/students', [\App\Http\Controllers\Api\Admin\ImportController::class, 'importStudents']);
    Route::post('/import/fee-rates', [\App\Http\Controllers\Api\Admin\ImportController::class, 'importFeeRates']);
    Route::get('/import/students/template', [\App\Http\Controllers\Api\Admin\ImportController::class, 'downloadStudentTemplate']);
    Route::get('/import/fee-rates/template', [\App\Http\Controllers\Api\Admin\ImportController::class, 'downloadFeeRateTemplate']);
});

// Dev-only convenience for exercising checkout end to end without a live
// gateway callback. This settled a guardian's own pending payment - their
// own bill only, visibleTo() saw to that - but with zero regard for whether
// any money actually moved, which makes it a free "mark my SPP as paid"
// button the moment it's reachable outside local/testing. It was: registered
// under the ordinary wali group with no environment guard at all. Gated at
// both the route (registered only outside production) and the controller
// (refuses unless local/testing) - a route file that gets copied without the
// surrounding comment should not be enough to reopen this.
if (app()->environment(['local', 'testing'])) {
    Route::middleware(['auth:sanctum', 'role:orangtua'])
        ->post('/wali/payments/{ulid}/simulate-settle', [WaliBillController::class, 'simulateSettle']);
}
