<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ─── OAuth Providers ──────────────────────────────────────────────────────

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'yahoo' => [
        'client_id'     => env('YAHOO_CLIENT_ID'),
        'client_secret' => env('YAHOO_CLIENT_SECRET'),
        'redirect'      => env('YAHOO_REDIRECT_URI', '/auth/yahoo/callback'),
    ],

    // Phase M3 — HIE gateway (null | sequoia)
    'hie' => [
        'driver' => env('HIE_DRIVER', 'null'),
    ],

    // Phase P5 — eligibility (X12 270/271) gateway (null | availity | change_healthcare)
    'eligibility' => [
        'driver' => env('ELIGIBILITY_DRIVER', 'null'),
    ],

];
