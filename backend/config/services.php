<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
        'client_secret' => env('GOOGLE_OAUTH_SECRET'),
        'redirect' => env('GOOGLE_OAUTH_REDIRECT_URI', env('APP_URL').'/api/auth/google/callback'),
    ],

    'hermes_safety' => [
        'enabled' => env('HERMES_SAFETY_ENABLED', false),
        'provider' => env('HERMES_SAFETY_PROVIDER'),
        'url' => env('HERMES_URL'),
        'key' => env('HERMES_KEY'),
        'kimi_key' => env('HERMES_SAFETY_KIMI_KEY', env('KIMI_API_KEY')),
        'base_url' => env('HERMES_SAFETY_BASE_URL'),
        'model' => env('HERMES_SAFETY_MODEL'),
        'timeout' => env('HERMES_TIMEOUT', 20),
    ],

];
