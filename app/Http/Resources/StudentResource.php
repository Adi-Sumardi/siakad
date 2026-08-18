<?php

namespace App\Http\Resources;

use App\Models\Bill;
use App\Models\PointThreshold;
use App\Models\Term;
use App\Services\Points\PointLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enrollment = $this->currentEnrollment();

        return [
            'ulid' => $this->ulid,
            'nama_lengkap' => $this->nama_lengkap,
            'nama_panggilan' => $this->nama_panggilan,
            'nis' => $this->nis,
            'jenis_kelamin' => $this->jenis_kelamin,
            'status' => $this->status,
            'unit' => $this->whenLoaded('schoolUnit', fn () => [
                'code' => $this->schoolUnit->code,
                'label' => $this->schoolUnit->label,
            ]),
            'kelas' => $enrollment ? [
                'name' => $enrollment->classroom->name,
                'tingkat' => $enrollment->classroom->tingkat,
                'wali_kelas' => $enrollment->classroom->homeroomTeacher?->name,
            ] : null,
            'poin' => $this->pointSummary(),
            'tunggakan' => $this->outstandingTotal(),
        ];
    }

    /** One SUM per child - fine for a guardian's handful; see PointController::index() for how the admin roster avoids the same query per row instead. */
    private function outstandingTotal(): float
    {
        return (float) Bill::where('student_id', $this->id)
            ->whereIn('status', Bill::OPEN_STATUSES)
            ->sum('remaining_amount');
    }

    /**
     * Fine for the handful of children one guardian's dashboard renders; not
     * meant for an admin list of hundreds, which should aggregate differently.
     */
    private function pointSummary(): ?array
    {
        $term = Term::current();

        if (! $term) {
            return null;
        }

        $balance = app(PointLedger::class)->balance($this->resource, $term);
        $threshold = PointThreshold::forBalance($balance, $this->school_unit_id);

        return [
            'balance' => $balance,
            'threshold' => $threshold ? [
                'label' => $threshold->label,
                'color' => $threshold->color,
            ] : null,
        ];
    }
}
