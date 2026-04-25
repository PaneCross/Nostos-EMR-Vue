<?php

use Illuminate\Support\Str;

return [

    'driver'          => env('SESSION_DRIVER', 'database'),
    // Phase P1 — HIPAA §164.312(a)(2)(iii) automatic logoff. 15 min idle is
    // the standard healthcare-auditor expectation. Override via SESSION_LIFETIME
    // if a tenant has documented compensating controls.
    'lifetime'        => env('SESSION_LIFETIME', 15),
    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', true),
    'encrypt'         => env('SESSION_ENCRYPT', false),
    'files'           => storage_path('framework/sessions'),
    'connection'      => env('SESSION_CONNECTION'),
    'table'           => env('SESSION_TABLE', 'sessions'),
    'store'           => env('SESSION_STORE'),
    'lottery'         => [2, 100],
    'cookie'          => env('SESSION_COOKIE', Str::slug(env('APP_NAME', 'laravel'), '_').'_session'),
    'path'            => env('SESSION_PATH', '/'),
    'domain'          => env('SESSION_DOMAIN'),
    'secure'          => env('SESSION_SECURE_COOKIE'),
    'http_only'       => env('SESSION_HTTP_ONLY', true),
    'same_site'       => env('SESSION_SAME_SITE', 'lax'),
    'partitioned'     => env('SESSION_PARTITIONED_COOKIE', false),

];
