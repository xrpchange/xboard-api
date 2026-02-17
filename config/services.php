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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
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

    'core' => [
        'url' => env('CORE_URL'),  // REQUIRED: Must be set in .env (sandbox Core URL)
        'key' => env('CORE_KEY', ''),
        'timeout' => env('CORE_TIMEOUT', 10),
    ],
    
    'core_production' => [
        'url' => env('CORE_PRODUCTION_URL'),  // REQUIRED: Must be set in .env (production Core URL)
        'key' => env('CORE_PRODUCTION_KEY', ''),
        'timeout' => env('CORE_PRODUCTION_TIMEOUT', 10),
    ],

    'dev' => [
        'mode' => env('DEV_MODE', 'sandbox'), // 'sandbox' or 'production'
    ],

    /*
    | Lot mailbox (IMAP chat) - mail.xdexboard.com
    | See docs/LOT_MAILBOX_AND_IMAP_CHAT.md
    */
    'lot_mailbox' => [
        'domain' => env('LOT_MAILBOX_DOMAIN', 'xdexboard.com'),
        'imap_host' => env('LOT_MAILBOX_IMAP_HOST', 'mail.xdexboard.com'),
        'imap_port' => env('LOT_MAILBOX_IMAP_PORT', 993),
        'imap_ssl' => env('LOT_MAILBOX_IMAP_SSL', true),
        'maildir_base' => env('LOT_MAILBOX_MAILDIR_BASE', '/var/mail/vhosts'), // for Maildir creation
    ],

];
