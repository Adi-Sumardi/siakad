<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Melindungi endpoint ringkasan yang dipanggil server portal Yapinet
 * (yapinet.id). Yapinet mengirim API key statis sebagai Bearer token;
 * nilainya diatur di menu Yapinet dan di YAPINET_API_KEY aplikasi ini.
 */
class EnsureYapinetApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $expected = config('services.yapinet.api_key');

        if (! $token || ! $expected || ! hash_equals($expected, $token)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
