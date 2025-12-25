<?php

/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUndefinedClassInspection */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\BankController;
use App\Http\Controllers\Api\ExchangeLotController;
use App\Http\Controllers\Api\BuyTriggerController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\NodeController;
use App\Http\Controllers\Api\EscrowTokenController;
use App\Http\Controllers\Api\Admin\NodeAdminController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Default Laravel route - not used in our project
// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

// OAuth callbacks (public, no authentication required)
// These endpoints receive OAuth callbacks from providers (Yandex, Gmail, Outlook)
// and redirect users back to the web application
// Note: Android uses SDK authentication with deep links, not these endpoints
Route::prefix('oauth')->group(function () {
    // Yandex OAuth callback for web
    // Registered redirect URI: https://api.xdexboard.com/api/oauth/yandex/callback
    Route::get('/yandex/callback', [\App\Http\Controllers\Api\OAuthController::class, 'yandexCallback']);
    
    // Gmail OAuth callback (for future implementation)
    Route::get('/gmail/callback', [\App\Http\Controllers\Api\OAuthController::class, 'gmailCallback']);
    
    // Outlook OAuth callback (for future implementation)
    Route::get('/outlook/callback', [\App\Http\Controllers\Api\OAuthController::class, 'outlookCallback']);
});

// OAuth token exchange endpoints (v1 API, public, no authentication required)
// Mobile apps use these endpoints to exchange authorization codes for access tokens
Route::prefix('v1/oauth')->group(function () {
    // Yandex OAuth token exchange (server-side, uses client_secret from server)
    // Mobile apps send authorization code here, server exchanges it for access token
    Route::post('/yandex/exchange-token', [\App\Http\Controllers\Api\OAuthController::class, 'yandexExchangeToken']);
    
    // Gmail OAuth token exchange (server-side, uses client_secret from server)
    Route::post('/gmail/exchange-token', [\App\Http\Controllers\Api\OAuthController::class, 'gmailExchangeToken']);
});

// Email access endpoints (v1 API, uses OAuth access token, no escrow token required)
// These endpoints use OAuth access tokens from email providers (Yandex, Gmail, Outlook)
// Access token is passed in request parameters/headers, not escrow token
// Currently used for testing email verification and DKIM validation (no transaction required)
Route::prefix('v1/payments')->group(function () {
    // Get email list and raw email (OAuth token required)
    Route::get('/emails', [\App\Http\Controllers\Api\PaymentVerificationController::class, 'getEmailList']);
    Route::get('/emails/{messageId}', [\App\Http\Controllers\Api\PaymentVerificationController::class, 'getRawEmail']);
    
    // DKIM verification (OAuth token required, no forwarding)
    Route::get('/verify-dkim/{messageId}', [\App\Http\Controllers\Api\PaymentVerificationController::class, 'verifyDkim']);
    
    // Email verification and forwarding (for testing DKIM validation only)
    Route::post('/verify-email', [\App\Http\Controllers\Api\PaymentVerificationController::class, 'verifyEmail']);
    Route::post('/verify-email-content', [\App\Http\Controllers\Api\PaymentVerificationController::class, 'verifyEmailContent']);
    Route::post('/forward-email', [\App\Http\Controllers\Api\PaymentVerificationController::class, 'forwardEmail']);
});

// Email accounts for IMAP monitoring (v1 API, no authentication required for now)
// TODO: Add proper authentication (OAuth or user session)
Route::prefix('v1/email-accounts')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\EmailAccountController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\EmailAccountController::class, 'store']);
    Route::delete('/{emailAccount}', [\App\Http\Controllers\Api\EmailAccountController::class, 'destroy']);
});

// Authentication (without middleware)
Route::prefix('v1/auth')->group(function () {
    // NEW: Simple token validation - no Bearer tokens, just validate publicPart
    Route::post('/validate-token', [AuthController::class, 'validateToken']);
    
    // DEPRECATED: Old Bearer token endpoints (kept for backward compatibility, will be removed)
    // Route::post('/bearer-token', [AuthController::class, 'getBearerToken']);
    // Route::get('/validate', [AuthController::class, 'validateBearerToken']);
});

// Reference data (without authentication)
Route::prefix('v1')->group(function () {
    // Health check
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'version' => '1.0',
            'data' => [
                'status' => 'ok',
                'timestamp' => now()->toISOString(),
                'message' => 'API is running'
            ]
        ]);
    });

    // Countries
    Route::prefix('countries')->group(function () {
        Route::get('/', [CountryController::class, 'index']);
        Route::get('/{country}', [CountryController::class, 'show']);
        Route::get('/{country}/banks', [CountryController::class, 'banks']);
    });

    // Currencies
    Route::prefix('currencies')->group(function () {
        Route::get('/fiat', [CurrencyController::class, 'fiat']);
        Route::get('/crypto', [CurrencyController::class, 'crypto']);
        Route::get('/all', [CurrencyController::class, 'all']);
    });

    // Banks
    Route::prefix('banks')->group(function () {
        Route::get('/', [BankController::class, 'index']);
        Route::get('/{bank}', [BankController::class, 'show']);
        Route::get('/country/{country:code}', [BankController::class, 'byCountry']);
    });

    // Nodes (exchange bulletin boards)
    Route::prefix('nodes')->group(function () {
        Route::get('/', [NodeController::class, 'index']);
        Route::get('/required', [NodeController::class, 'required']);
        Route::get('/{id}', [NodeController::class, 'show']);
        Route::post('/{id}/health-check', [NodeController::class, 'healthCheck']);
    });

    // Escrow Tokens (Stage 1 - no authentication required)
    Route::prefix('escrow-tokens')->group(function () {
        Route::get('/available-cryptos', [EscrowTokenController::class, 'getAvailableCryptos']);
        Route::post('/create', [EscrowTokenController::class, 'create']);
    });
    
    // Escrow Tokens (with authentication - for caching)
    Route::prefix('escrow-tokens')->middleware(\App\Http\Middleware\ValidateEscrowToken::class)->group(function () {
        Route::post('/validate-cache', [EscrowTokenController::class, 'validateCache']);
    });
});

// Protected routes (with escrow token authentication)
Route::prefix('v1')->middleware(\App\Http\Middleware\ValidateEscrowToken::class)->group(function () {
    // Exchange board
    Route::prefix('exchange-lots')->group(function () {
        Route::get('/', [ExchangeLotController::class, 'index']);
        Route::get('/{lot}', [ExchangeLotController::class, 'show']);
        Route::post('/', [ExchangeLotController::class, 'store']);
        Route::put('/{lot}', [ExchangeLotController::class, 'update']);
        Route::delete('/{lot}', [ExchangeLotController::class, 'destroy']);
    });

    // Buy triggers
    Route::prefix('buy-triggers')->group(function () {
        Route::get('/', [BuyTriggerController::class, 'index']);
        Route::get('/{trigger}', [BuyTriggerController::class, 'show']);
        Route::post('/', [BuyTriggerController::class, 'store']);
        Route::put('/{trigger}', [BuyTriggerController::class, 'update']);
        Route::delete('/{trigger}', [BuyTriggerController::class, 'destroy']);
    });

    // Transactions
    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('/stats', [TransactionController::class, 'stats']);
        Route::get('/{transaction}', [TransactionController::class, 'show']);
        Route::post('/', [TransactionController::class, 'store']);
        Route::put('/{transaction}', [TransactionController::class, 'update']);
    });

});

// Admin routes (require admin role)
Route::prefix('v1/admin')->middleware([\App\Http\Middleware\ValidateEscrowToken::class, 'admin'])->group(function () {
    // Node management
    Route::prefix('nodes')->group(function () {
        Route::post('/', [NodeAdminController::class, 'store']);
        Route::put('/{id}', [NodeAdminController::class, 'update']);
        Route::delete('/{id}', [NodeAdminController::class, 'destroy']);
        Route::put('/{id}/toggle', [NodeAdminController::class, 'toggle']);
        Route::post('/{id}/set-default', [NodeAdminController::class, 'setDefault']);
        Route::put('/{id}/endpoints', [NodeAdminController::class, 'updateEndpoints']);
    });
}); 