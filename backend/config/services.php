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

    /*
    |--------------------------------------------------------------------------
    | Twilio SMS
    |--------------------------------------------------------------------------
    | Optional environment-based fallback for the SMS gateway. Credentials are
    | normally managed through Admin > System & Integrations > SMS Gateway
    | Configuration (stored in the system_integrations table). These env values
    | are used when the database integration is missing or a value is not set.
    */
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token'  => env('TWILIO_AUTH_TOKEN'),
        'from'        => env('TWILIO_FROM'),
    ],

    'smsethiopia' => [
        'api_key' => env('SMSETHIOPIA_API_KEY'),
    ],

    'sms' => [
        'enabled'     => env('SMS_ENABLED', false),
        'provider'    => env('SMS_PROVIDER', 'twilio'),
        'webhook_url' => env('SMS_WEBHOOK_URL'),
    ],

];
