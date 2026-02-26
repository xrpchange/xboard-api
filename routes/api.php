<?php

/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUndefinedClassInspection */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
use App\Http\Controllers\Api\LotMailboxController;
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

// Email access endpoints (v1 API, uses IMAP/POP3 credentials, no escrow token required)
// These endpoints use IMAP/POP3 username/password for email access
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

// Email providers (v1 API, public, no authentication required)
Route::prefix('v1/email-providers')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\EmailProviderController::class, 'index']);
});

// Authentication (without middleware)
Route::prefix('v1/auth')->group(function () {
    Route::post('/validate-token', [AuthController::class, 'validateToken']);
});

// Reference data (without authentication)
Route::prefix('v1')->group(function () {
    // Health check — verifies banks table is reachable
    Route::get('/health', function () {
        $dbOk = false;
        $dbError = null;
        $dbHost = config('database.connections.' . config('database.default') . '.host');
        try {
            DB::connection()->getPdo();
            DB::table('banks')->limit(1)->exists();
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }
        return response()->json([
            'success' => true,
            'version' => '1.0',
            'data' => [
                'status' => $dbOk ? 'ok' : 'degraded',
                'timestamp' => now()->toISOString(),
                'message' => 'API is running',
                'database' => [
                    'reachable' => $dbOk,
                    'host' => $dbHost,
                    'error' => $dbError,
                ],
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
    Route::post('/lot-mailbox/delete', [LotMailboxController::class, 'delete']);

    Route::prefix('exchange-lots')->group(function () {
        Route::get('/', [ExchangeLotController::class, 'index']);
        Route::get('/{lot}', [ExchangeLotController::class, 'show']);
        Route::post('/', [ExchangeLotController::class, 'store']);
        Route::post('/{lot}/update', [ExchangeLotController::class, 'update']);
        Route::post('/{lot}/delete', [ExchangeLotController::class, 'destroy']);
        Route::post('/{lot}/set-active', [ExchangeLotController::class, 'setActive']);
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