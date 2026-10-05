<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * An opt-in source-IP allowlist (audit 2026-10-05).
 *
 * The e-SPP payment webhook carries no signature (e-SPP sends none), so its
 * only guards are the live verification against e-SPP's own record and a
 * throttle. When e-SPP can name the IP range their callbacks come from,
 * BILLING_API_WEBHOOK_ALLOWED_IPS turns this middleware on for that one
 * route - exact IPs or CIDR ranges, comma separated, behind the same
 * TRUSTED_PROXIES discipline the rest of the app uses. Empty (the default)
 * allows everything: the setting is an additional fence, never a new way
 * to break payments.
 */
class RestrictToIpRanges
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.billing_api.webhook_allowed_ips', '')),
        )));

        if ($allowed !== [] && ! IpUtils::checkIp($request->ip(), $allowed)) {
            abort(403, 'Sumber callback tidak dikenal.');
        }

        return $next($request);
    }
}
