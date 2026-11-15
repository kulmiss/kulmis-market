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

    // SMS: "log" (default, writes to the log) or "http" (any provider with a
    // JSON POST API: {to, message, sender} + Bearer token).
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'url' => env('SMS_URL'),
        'token' => env('SMS_TOKEN'),
        'sender' => env('SMS_SENDER', 'KulmisMkt'),
    ],

    // Phone notifications (Web Push, no message content is sent). Make keys with: php artisan km:push-keys
    'webpush' => [
        'public_key' => env('WEBPUSH_PUBLIC_KEY'),   // base64url, 65-byte P-256 point
        'private_key' => env('WEBPUSH_PRIVATE_KEY'), // base64 of the PEM private key
        'subject' => env('WEBPUSH_SUBJECT'),          // mailto:… or https://… (defaults to the support email)
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'channel_id' => env('TELEGRAM_CHANNEL_ID'),          // private signals channel, e.g. -1001234567890
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),  // checked on every webhook call
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),    // where you get reminders (optional)
    ],

];
