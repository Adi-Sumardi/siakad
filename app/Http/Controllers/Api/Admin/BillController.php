<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BillReasonRequest;
use App\Http\Requests\Admin\RecordBillPaymentRequest;
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
     * catalogue, same statuses, same payment lanes - VA checkout when e-SPP
     * has a prefix for the type (SPP, jamiyyah, ekskul, ...), cash at the
     * desk recorded through the usual lane otherwise. A per-unit admin only
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

        BillLine::create([
            'bill_id' => $bill->id,
            'name' => $validated['description'],
            'qty' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
            'sort_order' => 0,
        ]);

        ActivityLog::record($request->user(), 'bill.manual_created', $bill, [
            'bill_number' => $bill->bill_number,
            'student' => $student->nama_lengkap,
            'fee_type' => $feeType->code,
            'amount' => $amount,
        ]);

        return response()->json(['bill' => new BillResource($bill)], 201);
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
    public function waive(BillReasonRequest $request, string $ulid): JsonResponse
    {
        $validated = $request->validated();

        $bill = Bill::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        if (! $bill->isOpen()) {
            return response()->json(['message' => 'Hanya tagihan yang belum lunas yang bisa dibebaskan.'], 422);
        }

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

    public function cancel(BillReasonRequest $request, string $ulid): JsonResponse
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

    /** Cash at the front desk, or a transfer the admin has already confirmed. */
    public function recordPayment(RecordBillPaymentRequest $request, string $ulid, CheckoutService $checkout): JsonResponse
    {
        $validated = $request->validated();

        $bill = Bill::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        try {
            $payment = $checkout->recordManual(
                $bill,
                (float) $validated['amount'],
                $validated['method'],
                $request->user(),
                $bill->student->guardians()->wherePivot('is_billing_contact', true)->first(),
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLog::record($request->user(), 'payment.recorded_manually', $payment, [
            'bill_number' => $bill->bill_number,
            'amount' => (float) $validated['amount'],
            'method' => $validated['method'],
        ]);

        return response()->json([
            'payment' => new PaymentResource($payment),
            'bill' => new BillResource($bill->fresh()),
        ], 201);
    }
}
