<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SalesReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:mobile-login');

    Route::middleware(['auth:sanctum', 'throttle:mobile-api'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);

        // Tanpa ability terpisah (auth:sanctum saja) -- kasir yang sudah
        // login sebelum endpoint ini ada tetap langsung bisa pakai
        // Dashboard/Laporan tanpa perlu logout+login ulang dulu untuk
        // mendapat ability baru (ability Sanctum melekat pada token saat
        // diterbitkan, tidak bisa "ditambahkan" ke token yang sudah ada).
        Route::get('/dashboard/today', [DashboardController::class, 'today']);
        Route::get('/reports/sales', [SalesReportController::class, 'index']);

        Route::middleware('abilities:sync:pull')->group(function () {
            Route::get('/products', [ProductController::class, 'index']);
            Route::get('/products/stock', [ProductController::class, 'stock']);
            Route::get('/product-categories', [ProductCategoryController::class, 'index']);
            Route::get('/items', [ItemController::class, 'index']);
            Route::get('/items/stock', [ItemController::class, 'stock']);
        });

        Route::post('/sales', [SaleController::class, 'store'])
            ->middleware('abilities:sync:push');
        Route::get('/sales/{local_uuid}', [SaleController::class, 'show'])
            ->whereUuid('local_uuid')
            ->middleware('abilities:sync:status');
    });
});
