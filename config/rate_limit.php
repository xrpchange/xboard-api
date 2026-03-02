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

    /*
    |--------------------------------------------------------------------------
    | Verification code request rate limits (request-verification-code)
    |--------------------------------------------------------------------------
    | Prevents abuse: nobody can spam codes to arbitrary addresses.
    | Per IP and per email (recipient) limits in a 1-hour window.
    */

    'verification_code_request' => [
        'ip_per_hour' => (int) env('RATE_LIMIT_VERIFICATION_CODE_IP_PER_HOUR', 10),
        'email_per_hour' => (int) env('RATE_LIMIT_VERIFICATION_CODE_EMAIL_PER_HOUR', 5),
    ],

];
