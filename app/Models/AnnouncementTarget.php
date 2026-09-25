<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One targeted audience segment of an announcement (feature batch Poin 8):
 * the structured targeting the legacy school_unit_id/classroom_id columns
 * cannot express. Today the writer only ever stores kind='jenjang' with a
 * Jenjang ladder key as the value; the kind column stays open so future
 * multi-unit or multi-classroom targets land here instead of another
 * migration.
 */
class AnnouncementTarget extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'announcement_id',
        'kind',
        'value',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
