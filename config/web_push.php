<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Web Push (browser background deal notifications)
    |--------------------------------------------------------------------------
    |
    | VAPID keys: php artisan web-push:generate-vapid (writes .env lines).
    | When keys are empty, subscribe/send are no-ops.
    |
    */

    'enabled' => filter_var(env('WEB_PUSH_ENABLED', true), FILTER_VALIDATE_BOOL),

    'vapid' => [
        'subject' => env('WEB_PUSH_VAPID_SUBJECT', 'mailto:admin@xdexboard.com'),
        'public_key' => env('WEB_PUSH_VAPID_PUBLIC_KEY', ''),
        'private_key' => env('WEB_PUSH_VAPID_PRIVATE_KEY', ''),
    ],

];
