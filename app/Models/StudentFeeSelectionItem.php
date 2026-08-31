<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentFeeSelectionItem extends Model
{
    use HasUlidKey;

    protected $fillable = ['student_fee_selection_id', 'fee_component_id', 'included', 'size_option'];

    protected $casts = [
        'included' => 'boolean',
    ];

    public function selection(): BelongsTo
    {
        return $this->belongsTo(StudentFeeSelection::class, 'student_fee_selection_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(FeeComponent::class, 'fee_component_id');
    }
}
