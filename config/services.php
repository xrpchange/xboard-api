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
        'servers' => [
            'development' => [
                'sandbox' => [
                    'core' => [
                        'url' => env('CORE_DEVELOPMENT_SANDBOX_URL', 'http://sandbox.api.core.xrpchange.site'),
                        'key' => env('CORE_DEVELOPMENT_SANDBOX_KEY', ''),
                    ],
                ],
                'production' => [
                    'core' => [
                        'url' => env('CORE_DEVELOPMENT_PRODUCTION_URL', 'http://api.core.xrpchange.site'),
                        'key' => env('CORE_DEVELOPMENT_PRODUCTION_KEY', ''),
                    ],
                ],
            ],
            'sandbox' => [
                'core1' => [
                    'url' => env('CORE_SANDBOX_CORE1_URL', 'http://sandbox.core1.xrpchange.site'),
                    'key' => env('CORE_SANDBOX_CORE1_KEY', ''),
                ],
                'core2' => [
                    'url' => env('CORE_SANDBOX_CORE2_URL', 'http://sandbox.core2.xrpchange.site'),
                    'key' => env('CORE_SANDBOX_CORE2_KEY', ''),
                ],
                'core3' => [
                    'url' => env('CORE_SANDBOX_CORE3_URL', 'http://sandbox.core3.xrpchange.site'),
                    'key' => env('CORE_SANDBOX_CORE3_KEY', ''),
                ],
            ],
            'production' => [
                'core1' => [
                    'url' => env('CORE_PRODUCTION_CORE1_URL', 'http://prod.core1.xrpchange.site'),
                    'key' => env('CORE_PRODUCTION_CORE1_KEY', ''),
                ],
                'core2' => [
                    'url' => env('CORE_PRODUCTION_CORE2_URL', 'http://prod.core2.xrpchange.site'),
                    'key' => env('CORE_PRODUCTION_CORE2_KEY', ''),
                ],
                'core3' => [
                    'url' => env('CORE_PRODUCTION_CORE3_URL', 'http://prod.core3.xrpchange.site'),
                    'key' => env('CORE_PRODUCTION_CORE3_KEY', ''),
                ],
            ],
        ],
        'default_environment' => env('CORE_DEFAULT_ENV', 'development'),
        'timeout' => env('CORE_TIMEOUT', 10),
        'retry_attempts' => env('CORE_RETRY_ATTEMPTS', 3),
        // Путь для health-check ядра. По умолчанию '/echo.php'.
        'health_path' => env('CORE_HEALTH_PATH', '/echo.php'),
        // TTL (в секундах) для кэша последнего удачного сервера
        'cache_ttl' => env('CORE_CACHE_TTL', 86400),
        // Хранилище кэша (по умолчанию redis). Если не задано, используется дефолт Laravel
        'cache_store' => env('CORE_CACHE_STORE', 'redis'),
    ],

];
