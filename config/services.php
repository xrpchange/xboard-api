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
        'create_timeout' => env('CORE_CREATE_TIMEOUT', 120),
        'payout_timeout' => env('CORE_PAYOUT_TIMEOUT', 120),
    ],

    'core_production' => [
        'url' => env('CORE_PRODUCTION_URL'),  // REQUIRED: Must be set in .env (production Core URL)
        'key' => env('CORE_PRODUCTION_KEY', ''),
        'timeout' => env('CORE_PRODUCTION_TIMEOUT', 10),
        'create_timeout' => env('CORE_PRODUCTION_CREATE_TIMEOUT', env('CORE_CREATE_TIMEOUT', 120)),
        'payout_timeout' => env('CORE_PRODUCTION_PAYOUT_TIMEOUT', 120),
    ],

    'dev' => [
        'mode' => env('DEV_MODE', 'sandbox'), // 'sandbox' or 'production'
    ],

    'admin' => [
        'key' => env('ADMIN_API_KEY', ''), // Optional: for /v1/admin/* routes (X-Admin-Key or Bearer)
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
        'smtp_host' => env('LOT_MAILBOX_SMTP_HOST', env('LOT_MAILBOX_IMAP_HOST', 'mail.xdexboard.com')),
        'smtp_port' => (int) env('LOT_MAILBOX_SMTP_PORT', 587),
        'smtp_ssl' => env('LOT_MAILBOX_SMTP_SSL', true),
        'maildir_base' => env('LOT_MAILBOX_MAILDIR_BASE', '/var/mail/vhosts'), // for Maildir creation
    ],

    'arbiter' => [
        // Single active LLM provider only (no automatic failover to other models). Production: GigaChat.
        'primary_provider' => env('ARBITER_PRIMARY_PROVIDER', 'gigachat'),
        /** @deprecated Ignored — kept empty. Do not use `ARBITER_FALLBACK_PROVIDERS`; arbiter never switches providers. */
        'fallback_providers' => [],
        // Below this deterministic context score, skip LLM calls (rules-only manual outcome).
        'no_ai_below' => (float) env('ARBITER_NO_AI_BELOW', 0.35),
        // After a successful LLM response, escalate when context score is still below this threshold.
        'manual_review_threshold' => (float) env('ARBITER_MANUAL_REVIEW_THRESHOLD', 0.55),
        /** Max PDF bytes read from disk and sent to LLM providers that support a separate file (Anthropic document / OpenAI Responses input_file). */
        'receipt_pdf_max_bytes' => (int) env('ARBITER_RECEIPT_PDF_MAX_BYTES', 5242880),
        /** Max UTF-8 characters of server-extracted PDF text included in the text prompt for all providers (GigaChat/DeepSeek/…). */
        'receipt_pdf_text_excerpt_max_chars' => (int) env('ARBITER_RECEIPT_PDF_TEXT_EXCERPT_MAX_CHARS', 8000),
        // When LLM verdict is favor_buyer|favor_seller and arbiter_status is in_review, close deal_disputes so deal actions (e.g. seller crypto payout) are no longer blocked. Signing stays client-side (seller escrow token).
        'resolve_on_favor_buyer' => filter_var(env('ARBITER_RESOLVE_ON_FAVOR_BUYER', true), FILTER_VALIDATE_BOOL),
        'resolve_on_favor_seller' => filter_var(env('ARBITER_RESOLVE_ON_FAVOR_SELLER', true), FILTER_VALIDATE_BOOL),
        // Hard-stop for abusive buyer disputes: even if generic resolve_on_favor_seller is disabled, close buyer-opened dispute on AI favor_seller.
        'force_close_buyer_dispute_on_favor_seller' => filter_var(env('ARBITER_FORCE_CLOSE_BUYER_DISPUTE_ON_FAVOR_SELLER', true), FILTER_VALIDATE_BOOL),
        // When true and arbiter verdict is favor_buyer: Laravel calls Core POST /deal-payout-to-buyer-from-reservation (token from reservations / test_reservations).
        'auto_core_payout_on_favor_buyer' => filter_var(env('ARBITER_AUTO_CORE_PAYOUT_ON_FAVOR_BUYER', false), FILTER_VALIDATE_BOOL),
        'anthropic' => [
            'api_key' => env('ARBITER_ANTHROPIC_API_KEY', ''),
            'model' => env('ARBITER_ANTHROPIC_MODEL', 'claude-3-7-sonnet-latest'),
            'timeout' => (int) env('ARBITER_ANTHROPIC_TIMEOUT', 20),
        ],
        'openai' => [
            'api_key' => env('ARBITER_OPENAI_API_KEY', ''),
            'model' => env('ARBITER_OPENAI_MODEL', 'gpt-4o-mini'),
            'timeout' => (int) env('ARBITER_OPENAI_TIMEOUT', 20),
        ],
        'deepseek' => [
            'api_key' => env('ARBITER_DEEPSEEK_API_KEY', ''),
            'base_url' => rtrim((string) env('ARBITER_DEEPSEEK_BASE_URL', 'https://api.deepseek.com'), '/'),
            'model' => env('ARBITER_DEEPSEEK_MODEL', 'deepseek-chat'),
            'timeout' => (int) env('ARBITER_DEEPSEEK_TIMEOUT', 30),
        ],
        'gigachat' => [
            // Authorization key from Sber developer portal: either raw Base64 (used as Basic <value>) or full "Basic ..." / "Bearer ..." header value.
            'oauth_credential' => (string) env('ARBITER_GIGACHAT_AUTHORIZATION_KEY', ''),
            'scope' => env('ARBITER_GIGACHAT_SCOPE', 'GIGACHAT_API_PERS'),
            'oauth_url' => rtrim((string) env('ARBITER_GIGACHAT_OAUTH_URL', 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth'), '/'),
            'api_base_url' => rtrim((string) env('ARBITER_GIGACHAT_API_BASE_URL', 'https://gigachat.devices.sberbank.ru/api/v1'), '/'),
            'model' => env('ARBITER_GIGACHAT_MODEL', 'GigaChat'),
            'timeout' => (int) env('ARBITER_GIGACHAT_TIMEOUT', 45),
            // Sber TLS + dev CA bundles often disagree; default verify only in production unless overridden.
            'verify_ssl' => filter_var(
                env(
                    'ARBITER_GIGACHAT_VERIFY_SSL',
                    env('APP_ENV', 'local') === 'production' ? '1' : '0'
                ),
                FILTER_VALIDATE_BOOL
            ),
        ],
    ],

];
