<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Moodle Configuration
    |--------------------------------------------------------------------------
    */

    'moodle' => [
        'url' => env('MOODLE_URL'),
        'token' => env('MOODLE_TOKEN'),
        'service' => env('MOODLE_SERVICE', 'laravel2'),
        'default_course_id' => env('MOODLE_DEFAULT_COURSE_ID', 2),
        'timeout' => env('MOODLE_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Medusa Configuration
    |--------------------------------------------------------------------------
    */

    'medusa' => [
        'url' => env('MEDUSA_URL'),
        'publishable_key' => env('MEDUSA_PUBLISHABLE_KEY'),
        'medusa_webhook_secret' => env('MEDUSA_WEBHOOK_SECRET'),
    ],

];