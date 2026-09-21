<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\VerifyPmbSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cookie/session auth for the Next.js SPA. Without this the api group
        // is stateless and every request from the browser reads as a guest.
        $middleware->statefulApi();

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'active' => EnsureUserIsActive::class,
            'pmb.signature' => VerifyPmbSignature::class,
        ]);

        // The signature is computed over the raw body, so nothing may rewrite
        // it in transit - CSRF does not apply to a machine-to-machine call that
        // authenticates with an HMAC.
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/*',
        ]);

        // Behind nginx and a Cloudflare tunnel; without this the app sees the
        // proxy's IP in throttling and logs, and builds http:// URLs.
        // An explicit list, never '*': a wildcard also trusts the CLIENT, so
        // anyone sending their own X-Forwarded-For picks their rate-limit
        // identity (OTP brute force, public check-in, webhooks). With an
        // explicit list Symfony walks the chain from the trusted proxy
        // hop backwards and stops at the first untrusted address - forged
        // entries past the real client IP are ignored. Default covers
        // loopback plus the RFC1918 ranges the docker networks live in;
        // override via TRUSTED_PROXIES (comma separated IPs/CIDRs).
        $middleware->trustProxies(at: array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')),
        )));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
