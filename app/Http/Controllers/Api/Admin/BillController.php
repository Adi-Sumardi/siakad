<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BillReasonRequest;
use App\Http\Requests\Admin\IssueVirtualAccountRequest;
use App\Http\Requests\Admin\StoreManualBillRequest;
use App\Http\Resources\BillResource;
use App\Http\Resources\PaymentResource;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\FeeType;
use App\Models\Student;
use App\Models\Term;
use App\Services\Billing\BillPdfService;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\VaIssuedNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class BillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bills = Bill::query()
            ->visibleTo($request->user())
            ->with(['student.schoolUnit', 'feeType'])
            ->when($request->string('status')->value(), fn ($q, $status) => $status === 'open'
                ? $q->open()
                : $q->where('status', $status))
            ->when($request->string('type')->value(), fn ($q, $code) => $q->whereHas('feeType', fn ($t) => $t->where('code', $code)))
            ->when($request->integer('month'), fn ($q, $month) => $q->where('period_month', $month))
            ->when($request->string('q')->value(), fn ($q, $term) => $q->whereHas('student',
                fn ($s) => $s->where('nama_lengkap', 'like', "%{$term}%")))
            // One student's bills - what the manual-bill form asks before it
            // offers to issue another cambridge bill for the same year.
            // visibleTo() still narrows a per-unit admin, so a foreign ULID
            // simply yields an empty page.
            ->when($request->string('student')->value(), fn ($q, $ulid) => $q->whereHas('student',
                fn ($s) => $s->where('ulid', $ulid)))
            // The tagihan page has always sent unit and year filters; they
            // are applied inside visibleTo(), so a per-unit admin's scope
            // wins no matter what the dropdown claimed.
            ->when($request->string('unit')->value(), fn ($q, $unitCode) => $q->whereHas('student.schoolUnit',
                fn ($u) => $u->where('code', $unitCode)))
            ->when($request->string('year')->value(), fn ($q, $year) => $q->whereHas('academicYear',
                fn ($ay) => $ay->where('year', $year)->orWhere('ulid', $year)))
            ->orderBy('due_date')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'bills' => BillResource::collection($bills)->response()->getData(true),
        ]);
    }

    /**
     * A one-off bill for the cases the scheduled generator never knows about
     * (replacement uniform, a mid-year entry's missed month, a fine). The
     * money context is identical to a generated bill: same fee type
     * catalogue, same statuses, same payment lane - VA checkout, which the
     * family can actually complete only once e-SPP has a prefix for the
     * type (SPP, jamiyyah, ekskul, ...). A per-unit admin only
     * reaches students inside their own unit (visibleTo, R3 - 404 not 403).
     */
    public function storeManual(StoreManualBillRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $student = Student::visibleTo($request->user())
            ->where('ulid', $validated['student_ulid'])
            ->firstOrFail();

        $feeType = FeeType::where('ulid', $validated['fee_type_ulid'])
            ->where('is_active', true)
            ->firstOrFail();

        // The one manual bill a per-unit admin may issue: cambridge, whose
        // nominal (book cost included) each unit sets per student - the same
        // line FeeSettingController draws for the rate itself. Everything
        // else stays a central-admin decision.
        if ($request->user()->isUnitScoped()) {
            abort_unless($feeType->code === FeeSettingController::UNIT_MANAGED_FEE_CODE, 403,
                'Admin unit hanya boleh menerbitkan tagihan manual jenis Cambridge.');
        }

        $year = AcademicYear::where('is_active', true)->first()
            ?? AcademicYear::latest('starts_on')->first();

        if (! $year) {
            return response()->json(['message' => 'Belum ada tahun ajaran di sistem.'], 422);
        }

        $amount = round((float) $validated['amount'], 2);

        $bill = Bill::create([
            'bill_number' => Bill::generateNumber($feeType, $year->year),
            'student_id' => $student->id,
            'fee_type_id' => $feeType->id,
            'academic_year_id' => $year->id,
            'term_id' => Term::current()?->id,
            'dedup_key' => 'manual:'.$student->id.':'.uniqid(),
            'description' => $validated['description'],
            'subtotal' => $amount,
            'discount_amount' => 0,
            'late_fee' => 0,
            'total_amount' => $amount,
            'paid_amount' => 0,
            'remaining_amount' => $amount,
            'status' => 'unpaid',
            'due_date' => $validated['due_date'],
            'allow_installment' => (bool) $feeType->allow_installment,
            'issued_at' => now(),
            'issued_by' => $request->user()->id,
            'notes' => 'Diterbitkan manual oleh '.$request->user()->name,
        ]);

        // With an itemised breakdown (cambridge + buku on one bill) each row
        // becomes its own line; without one the description stays the single
        // line, exactly as before. The request guarantees the rows sum to
        // $amount either way.
        $lines = $validated['lines'] ?? null;

        if ($lines) {
            foreach ($lines as $i => $line) {
                BillLine::create([
                    'bill_id' => $bill->id,
                    'name' => $line['name'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'amount' => round($line['qty'] * $line['unit_price'], 2),
                    'sort_order' => $i,
                ]);
            }
        } else {
            BillLine::create([
                'bill_id' => $bill->id,
                'name' => $validated['description'],
                'qty' => 1,
                'unit_price' => $amount,
                'amount' => $amount,
                'sort_order' => 0,
            ]);
        }

        ActivityLog::record($request->user(), 'bill.manual_created', $bill, [
            'bill_number' => $bill->bill_number,
            'student' => $student->nama_lengkap,
            'fee_type' => $feeType->code,
            'amount' => $amount,
            'line_count' => count($lines ?? [['name' => $validated['description']]]),
        ]);

        return response()->json(['bill' => new BillResource($bill)], 201);
    }

    /**
     * "Buat VA" - an admin mints the Virtual Account for one bill on the
     * family's behalf. The same checkout lane the wali walks (basket rules,
     * prefix assertion, supersede guards, allocator) with the student's
     * billing contact as the payer, so the payment lands in that family's
     * own /api/wali/payments feed and the VA number is pushed to their
     * WhatsApp. VA-only by the school's decision: there is deliberately no
     * staff cash lane next to this.
     */
    public function storeVa(IssueVirtualAccountRequest $request, string $ulid, CheckoutService $checkout, VaIssuedNotifier $whatsapp): JsonResponse
    {
        $validated = $request->validated();

        $bill = Bill::query()
            ->visibleTo($request->user())
            ->with(['student', 'feeType'])
            ->where('ulid', $ulid)
            ->firstOrFail();

        // The same line storeManual and the fee-rate writes draw: a per-unit
        // admin's money moves are cambridge-shaped only.
        if ($request->user()->isUnitScoped()) {
            abort_unless($bill->feeType->code === FeeSettingController::UNIT_MANAGED_FEE_CODE, 403,
                'Admin unit hanya boleh membuat Virtual Account untuk tagihan Cambridge.');
        }

        if (! $bill->isOpen()) {
            return response()->json([
                'message' => 'Hanya tagihan yang belum lunas yang bisa dibuatkan Virtual Account.',
            ], 422);
        }

        $guardian = $bill->student->billingContact();

        if (! $guardian) {
            // Refused before any payment row exists: a VA nobody can be told
            // about is a number waiting to confuse everyone.
            return response()->json([
                'message' => 'Siswa ini belum terhubung dengan wali mana pun - Virtual Account tidak bisa diterbitkan. Lengkapi data wali siswa terlebih dahulu.',
            ], 422);
        }

        try {
            $payment = $checkout->startForGuardian(
                $request->user(),
                $guardian,
                [$bill->ulid],
                'virtual_account',
                [],
                $validated['bank'] ?? 'muamalat',
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Best-effort: the VA exists and the family can always find it in the
        // app; a WhatsApp hiccup lands in notification_logs for the sweep.
        $push = $whatsapp->notify($payment);

        ActivityLog::record($request->user(), 'payment.va_issued', $payment, [
            'bill_number' => $bill->bill_number,
            'va_number' => $payment->gateway_response['va_number'] ?? null,
            'bank' => $validated['bank'] ?? 'muamalat',
            'payer' => $guardian->nama,
        ]);

        return response()->json([
            'payment' => new PaymentResource($payment),
            'whatsapp' => [
                'sent' => $push->success,
                'reason' => $push->message,
            ],
        ], 201);
    }

    public function pdf(Request $request, string $ulid, BillPdfService $pdf): Response
    {
        $bill = Bill::query()
            ->visibleTo($request->user())
            ->where('ulid', $ulid)
            ->firstOrFail();

        return $pdf->render($bill)->stream($pdf->filename($bill));
    }

    /**
     * Writes the bill off. Requires a reason, because the alternative is a
     * balance that silently disappeared and nobody can account for at audit.
     */
    public function waive(BillReasonRequest $request, string $ulid, CheckoutService $checkout): JsonResponse
    {
        $validated = $request->validated();

        $bill = Bill::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        if (! $bill->isOpen()) {
            return response()->json(['message' => 'Hanya tagihan yang belum lunas yang bisa dibebaskan.'], 422);
        }

        // A waived bill must not keep a live bank invoice: the parent paying
        // the VA an hour later is money against a decision, not a bill. Voids
        // first and aborts loudly if the bank says the VA was already paid.
        try {
            $checkout->voidPendingPaymentsFor($bill, 'Tagihan dibebaskan: '.$validated['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $bill->refresh();

        $bill->forceFill([
            'status' => 'waived',
            'remaining_amount' => 0,
            'notes' => trim(($bill->notes ? $bill->notes."\n" : '').'Dibebaskan: '.$validated['reason']),
        ])->save();

        ActivityLog::record($request->user(), 'bill.waived', $bill, [
            'bill_number' => $bill->bill_number,
            'amount' => (float) $bill->total_amount,
            'reason' => $validated['reason'],
        ]);

        return response()->json(['bill' => new BillResource($bill->fresh())]);
    }

    public function cancel(BillReasonRequest $request, string $ulid, CheckoutService $checkout): JsonResponse
    {
        $validated = $request->validated();

        $bill = Bill::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        if ((float) $bill->paid_amount > 0) {
            // Money has already been received against it; cancelling would
            // strand that payment with nothing to point at.
            return response()->json([
                'message' => 'Tagihan yang sudah menerima pembayaran tidak bisa dibatalkan. Gunakan refund.',
            ], 422);
        }

        // Same guard as waive: no live bank invoice may outlive a cancelled
        // bill. If the bank reports the VA already paid, the void settles it
        // and the exception aborts the cancellation - the bill is now paid,
        // which is the opposite of what the admin came here to do.
        try {
            $checkout->voidPendingPaymentsFor($bill, 'Tagihan dibatalkan: '.$validated['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $bill->refresh();

        $bill->forceFill([
            'status' => 'cancelled',
            'remaining_amount' => 0,
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
            'cancel_reason' => $validated['reason'],
        ])->save();

        ActivityLog::record($request->user(), 'bill.cancelled', $bill, [
            'bill_number' => $bill->bill_number,
            'reason' => $validated['reason'],
        ]);

        return response()->json(['bill' => new BillResource($bill->fresh())]);
    }

    /**
     * Note: there is deliberately no staff-recorded cash/transfer lane any
     * more (school decision 2026-09-21: payment flows ONLY through the
     * Virtual Account channels e-SPP provides). A fee type without a VA
     * prefix therefore cannot be paid through the system at all until e-SPP
     * registers its prefix - see the has_va_prefix flag on fee-types.
     */
}
