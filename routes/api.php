<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\BankController;
use App\Http\Controllers\Api\ExchangeLotController;
use App\Http\Controllers\Api\BuyTriggerController;
use App\Http\Controllers\Api\TransactionController;

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Аутентификация (без middleware)
Route::prefix('v1/auth')->group(function () {
    Route::post('/bearer-token', [AuthController::class, 'getBearerToken']);
    Route::get('/validate', [AuthController::class, 'validateBearerToken']);
});

// Справочники (без аутентификации)
Route::prefix('v1')->group(function () {
    // Страны
    Route::prefix('countries')->group(function () {
        Route::get('/', [CountryController::class, 'index']);
        Route::get('/{country}', [CountryController::class, 'show']);
        Route::get('/{country}/banks', [CountryController::class, 'banks']);
    });

    // Валюты
    Route::prefix('currencies')->group(function () {
        Route::get('/fiat', [CurrencyController::class, 'fiat']);
        Route::get('/crypto', [CurrencyController::class, 'crypto']);
        Route::get('/all', [CurrencyController::class, 'all']);
    });

    // Банки
    Route::prefix('banks')->group(function () {
        Route::get('/', [BankController::class, 'index']);
        Route::get('/{bank}', [BankController::class, 'show']);
        Route::get('/country/{country:code}', [BankController::class, 'byCountry']);
    });
});

// Защищенные роуты (с аутентификацией)
Route::prefix('v1')->middleware('bearer.token')->group(function () {
    // Доска объявлений
    Route::prefix('exchange-lots')->group(function () {
        Route::get('/', [ExchangeLotController::class, 'index']);
        Route::get('/{lot}', [ExchangeLotController::class, 'show']);
        Route::post('/', [ExchangeLotController::class, 'store']);
        Route::put('/{lot}', [ExchangeLotController::class, 'update']);
        Route::delete('/{lot}', [ExchangeLotController::class, 'destroy']);
    });

    // Триггеры покупки
    Route::prefix('buy-triggers')->group(function () {
        Route::get('/', [BuyTriggerController::class, 'index']);
        Route::get('/{trigger}', [BuyTriggerController::class, 'show']);
        Route::post('/', [BuyTriggerController::class, 'store']);
        Route::put('/{trigger}', [BuyTriggerController::class, 'update']);
        Route::delete('/{trigger}', [BuyTriggerController::class, 'destroy']);
    });

    // Транзакции
    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('/stats', [TransactionController::class, 'stats']);
        Route::get('/{transaction}', [TransactionController::class, 'show']);
        Route::post('/', [TransactionController::class, 'store']);
        Route::put('/{transaction}', [TransactionController::class, 'update']);
    });
}); 