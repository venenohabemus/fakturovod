<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ErrorQueueController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MappingController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::redirect('/', '/faktury');

    Route::get('/faktury', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('/faktury/import', [InvoiceController::class, 'upload'])->name('invoices.upload');
    Route::post('/faktury/spracovat', [InvoiceController::class, 'processAll'])->name('invoices.process');
    Route::get('/faktury/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('/faktury/{invoice}/znova', [InvoiceController::class, 'retry'])->name('invoices.retry');
    Route::get('/faktury/{invoice}/ubl', [InvoiceController::class, 'downloadUbl'])->name('invoices.ubl');

    Route::get('/chyby', [ErrorQueueController::class, 'index'])->name('errors.index');

    Route::get('/spotreba', [UsageController::class, 'index'])->name('usage.index');

    Route::get('/mapovania', [MappingController::class, 'index'])->name('mappings.index');
    Route::post('/mapovania', [MappingController::class, 'store'])->name('mappings.store');
    Route::get('/mapovania/{mapping}', [MappingController::class, 'edit'])->name('mappings.edit');
    Route::put('/mapovania/{mapping}', [MappingController::class, 'update'])->name('mappings.update');
});
