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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'bc' => [
        'url' => env('BC_URL', 'https://api.businesscentral.dynamics.com'),
        'tenant_id' => env('BC_TENANT_ID'),
        'client_id' => env('BC_CLIENT_ID'),
        'client_secret' => env('BC_CLIENT_SECRET'),
        'instance' => env('BC_INSTANCE'),
        'company_id' => env('BC_COMPANY_ID'),
        'api_version' => env('BC_API_VERSION', 'v2.0'),
        'http_timeout' => (int) env('BC_HTTP_TIMEOUT', 30),
        'http_connect_timeout' => (int) env('BC_HTTP_CONNECT_TIMEOUT', 10),
    ],

];
