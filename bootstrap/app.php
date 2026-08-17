<?php

use App\Http\Middleware\EnsureUserHasRole;
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
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
