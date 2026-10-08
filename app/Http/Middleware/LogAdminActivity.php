<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ActivityCatalog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log Aktivitas safety net (2026-10-01), on the /api/admin groups. Most
 * admin actions already write their own ActivityLog::record() row; this makes
 * sure the rest are not missing:
 *  - a change (POST/PUT/PATCH/DELETE) or a download that wrote no row of its
 *    own - including one refused or failed - gets a row here, named from its
 *    controller method (ActivityCatalog);
 *  - rows the action did write get the response status filled in.
 * Plain reads are not logged. Never breaks the request.
 */
class LogAdminActivity
{
    private const DOWNLOAD_METHODS = ['pdf', 'exportDapodik', 'downloadUserTemplate', 'downloadStudentTemplate', 'downloadFeeRateTemplate'];

    public function handle(Request $request, Closure $next): Response
    {
        ActivityLog::$recordedThisRequest = [];
        $plan = $this->plan($request);
        $response = $next($request);

        if (! $plan) {
            return $response;
        }

        try {
            if (ActivityLog::$recordedThisRequest !== []) {
                ActivityLog::whereKey(ActivityLog::$recordedThisRequest)->update(['status' => $response->getStatusCode()]);
            } else {
                $this->writeOwnRow($request, $plan, $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            Log::warning('Admin activity log write failed', ['path' => $request->path(), 'error' => $e->getMessage()]);
        }

        ActivityLog::$recordedThisRequest = [];

        return $response;
    }

    /** @return array{key: string, subject: ?Model}|null */
    private function plan(Request $request): ?array
    {
        $user = $request->user();
        $route = $request->route();

        if (! $user instanceof User || ! in_array($user->role, ['admin', 'admin_unit'], true) || ! $route instanceof Route) {
            return null;
        }

        $key = class_basename((string) $route->getControllerClass()).'@'.$route->getActionMethod();
        $reading = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        if (in_array($key, ActivityCatalog::IGNORED, true) || ($reading && ! in_array($route->getActionMethod(), self::DOWNLOAD_METHODS, true))) {
            return null;
        }

        // Taken before the action runs, so a deletion still names its record.
        $subject = collect($route->parameters())->first(fn ($v) => $v instanceof Model);

        return ['key' => $key, 'subject' => $subject];
    }

    /** @param array{key: string, subject: ?Model} $plan */
    private function writeOwnRow(Request $request, array $plan, int $status): void
    {
        $user = $request->user();
        $user->loadMissing('schoolUnit');
        $described = ActivityCatalog::describe($plan['key']);
        $subject = $plan['subject'];

        ActivityLog::create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'role' => $user->role,
            'school_unit_id' => $user->school_unit_id,
            'unit_label' => $user->schoolUnit?->label,
            'action' => 'admin.'.$plan['key'],
            'label' => $described['label'],
            'category' => $described['category'],
            'status' => $status,
            'path' => mb_substr('/'.$request->path(), 0, 500),
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            // Field names only, never values - they may be personal data.
            'meta' => array_filter([
                'data' => $subject ? $this->describe($subject) : null,
                'fields' => array_values(array_diff(array_keys($request->except(['_token', '_method', 'password', 'token'])), ['password', 'token'])) ?: null,
            ]) ?: null,
            'created_at' => now(),
        ]);
    }

    private function describe(Model $model): string
    {
        foreach (['nama_lengkap', 'name', 'title', 'label', 'code'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 200);
            }
        }

        return class_basename($model);
    }
}
