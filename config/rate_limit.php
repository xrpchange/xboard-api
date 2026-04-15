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

    /*
    |--------------------------------------------------------------------------
    | Proof-of-work puzzle (request-verification-code)
    |--------------------------------------------------------------------------
    | Client must find puzzle_answer such that hex(SHA256(puzzle_token + puzzle_answer))
    | starts with prefix. Stops mass bots (1000 IPs) — each request costs CPU.
    | prefix_hex_length: number of leading zero hex chars (4 = "0000" ≈ 65k hashes).
    */

    'verification_puzzle' => [
        'prefix_hex_length' => (int) env('RATE_LIMIT_PUZZLE_PREFIX_HEX_LENGTH', 4),
        'token_ttl_seconds' => (int) env('RATE_LIMIT_PUZZLE_TOKEN_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin mode enable attempts (client-side Developer Settings)
    |--------------------------------------------------------------------------
    | Limits password authorization attempts to enable Administrator mode.
    | Applies per device_id (or IP fallback) in a daily window.
    */
    'admin_mode_enable' => [
        'attempts_per_day' => (int) env('RATE_LIMIT_ADMIN_MODE_ATTEMPTS_PER_DAY', 10),
    ],

];
