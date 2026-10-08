<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasUlidKey;

    public $timestamps = false;

    /**
     * Rows written during the current request, so LogAdminActivity knows
     * whether an explicit record() already covered it. Reset per request.
     *
     * @var list<int>
     */
    public static array $recordedThisRequest = [];

    protected $fillable = [
        'user_id',
        'user_name',
        'role',
        'school_unit_id',
        'unit_label',
        'action',
        'label',
        'category',
        'status',
        'path',
        'subject_type',
        'subject_id',
        'ip_address',
        'user_agent',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Every money and points action records one of these. */
    public static function record(?User $user, string $action, ?Model $subject = null, array $meta = []): self
    {
        $user?->loadMissing('schoolUnit');
        $described = \App\Support\ActivityCatalog::describe($action);

        $log = static::create([
            'user_id' => $user?->id,
            // As they were at the time - see the 2026_10_01 migration.
            'user_name' => $user?->name,
            'role' => $user?->role,
            'school_unit_id' => $user?->school_unit_id,
            'unit_label' => $user?->schoolUnit?->label,
            'action' => $action,
            'label' => $described['label'],
            'category' => $described['category'],
            'path' => request()->path() ? mb_substr('/'.request()->path(), 0, 500) : null,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);

        static::$recordedThisRequest[] = $log->id;

        return $log;
    }
}
