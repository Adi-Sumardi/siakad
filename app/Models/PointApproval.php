<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stage's decision on a point record - the trail of who let it through, or stopped it. */
class PointApproval extends Model
{
    public const STAGE_LABEL = [
        'wali_kelas' => 'Wali Kelas',
        'waka_kesiswaan' => 'Waka Kesiswaan',
        'kepala_sekolah' => 'Kepala Sekolah',
    ];

    protected $fillable = ['point_record_id', 'stage', 'decision', 'decided_by', 'note'];

    public function pointRecord(): BelongsTo
    {
        return $this->belongsTo(PointRecord::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
