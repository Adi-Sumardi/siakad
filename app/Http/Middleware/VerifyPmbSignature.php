<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies that a handoff really came from PMB.
 *
 * HMAC-SHA256 over the raw body with a shared secret - the same shape as the
 * Xendit callback checks PMB already runs, so there is no second mechanism for
 * anyone to learn.
 */
class VerifyPmbSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.pmb.handoff_secret');

        if (empty($secret)) {
            // Refusing is the only safe answer. Accepting unsigned handoffs
            // "until the secret is configured" would leave an endpoint that
            // creates students and mails out account links open to anyone.
            Log::error('[PMB handoff] PMB_HANDOFF_SECRET is not configured; rejecting request.');

            return response()->json(['message' => 'Handoff endpoint is not configured.'], 503);
        }

        $provided = (string) $request->header('X-PMB-Signature');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        // hash_equals, not ===: a byte-by-byte comparison leaks how much of a
        // forged signature was correct through its timing.
        if (! $provided || ! hash_equals($expected, $provided)) {
            Log::warning('[PMB handoff] Invalid signature', [
                'ip' => $request->ip(),
                'event_id' => $request->input('event_id'),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return $next($request);
    }
}
