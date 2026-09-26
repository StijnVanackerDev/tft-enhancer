<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'riot' => [
        'key' => env('RIOT_API_KEY'),
        'match_count' => (int) env('RIOT_MATCH_COUNT', 20),
        // Matches per player loaded for a lobby analysis.
        'lobby_history' => (int) env('RIOT_LOBBY_HISTORY', 15),
        // Used until Riot reports the real limits in its response headers.
        // Development keys: 20 per second and 100 per 2 minutes.
        'rate_limits' => env('RIOT_RATE_LIMITS', '20:1,100:120'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
