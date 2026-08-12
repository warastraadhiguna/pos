<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DraftController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\NoteTemplateController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SalesReportController;
use App\Http\Controllers\Api\TableController;
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

        // Device Binding -- cek berkala (poin 5 rancangan), ability
        // terpisah (bukan sync:*) supaya token lama pra-fitur ini (tanpa
        // ability ini) tidak bisa memanggilnya sama sekali -- mereka juga
        // tidak diharuskan, karena login mereka sudah lolos tanpa
        // device_id (fitur ini belum ada saat token itu diterbitkan).
        Route::get('/device/status', [DeviceController::class, 'status'])
            ->middleware('abilities:device:status');

        Route::middleware('abilities:sync:pull')->group(function () {
            Route::get('/products', [ProductController::class, 'index']);
            Route::get('/products/stock', [ProductController::class, 'stock']);
            Route::get('/product-categories', [ProductCategoryController::class, 'index']);
            Route::get('/items', [ItemController::class, 'index']);
            Route::get('/items/stock', [ItemController::class, 'stock']);
            Route::get('/members', [MemberController::class, 'index']);
            Route::get('/tables', [TableController::class, 'index']);
            Route::get('/note-templates', [NoteTemplateController::class, 'index']);
            // Langkah 3 fitur Draft -- pull draft lintas-device, pola sama
            // master data lain di grup ini (updated_since inkremental).
            Route::get('/drafts', [DraftController::class, 'index']);
        });

        Route::post('/sales', [SaleController::class, 'store'])
            ->middleware('abilities:sync:push');
        Route::get('/sales/{local_uuid}', [SaleController::class, 'show'])
            ->whereUuid('local_uuid')
            ->middleware('abilities:sync:status');

        Route::middleware('abilities:sync:push')->group(function () {
            Route::post('/drafts', [DraftController::class, 'store']);
            Route::post('/drafts/{draft}/hold', [DraftController::class, 'hold']);
            Route::post('/drafts/{draft}/release', [DraftController::class, 'release']);
            Route::delete('/drafts/{draft}', [DraftController::class, 'destroy']);
        });
    });
});
