<?php

/*
|--------------------------------------------------------------------------
| CORS Configuration
|--------------------------------------------------------------------------
|
| The frontend is a separate origin from the API (its own port in dev, its
| own subdomain in production) and every authenticated request sends the
| Sanctum session cookie - supports_credentials must be true, and the
| origin echoed back must be the frontend's own, never '*'. Without a
| published config here Laravel's own default is supports_credentials =>
| false, which a real browser rejects outright on any credentialed
| request: the fetch never completes, and the only sign of it is a bare
| "Failed to fetch" with nothing more specific in the console.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [config('app.frontend_url')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
