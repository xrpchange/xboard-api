<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Escrow token creation rate limits
    |--------------------------------------------------------------------------
    | Protects against bots and abuse. Combined: per-email (monthly) and per-IP (daily).
    | Home use expected; office/shared IPs may hit IP limit.
    */

    'token_creation' => [
        'email_per_month_production' => (int) env('RATE_LIMIT_TOKEN_EMAIL_MONTH_PROD', 10),
        'email_per_month_sandbox' => (int) env('RATE_LIMIT_TOKEN_EMAIL_MONTH_SANDBOX', 30),
        'ip_per_day' => (int) env('RATE_LIMIT_TOKEN_IP_DAY', 20),
    ],

];
