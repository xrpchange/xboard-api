<?php

use App\Http\Controllers\OpenDealController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

/** Public: opens app from seller email (HTTPS link is mail-client friendly). */
Route::get('/open-deal', [OpenDealController::class, 'show'])->name('deal.open');

// Swagger документация
Route::get('/api/documentation', function () {
    return view('swagger.index');
});

Route::get('/docs', function () {
    return redirect('/api/documentation');
});

// API docs JSON
Route::get('/docs/api-docs.json', function () {
    return response()->file(storage_path('api-docs/api-docs.json'));
});
