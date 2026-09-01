<?php

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CustomerAccountController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\ReadinessController;
use App\Http\Controllers\Api\V1\SizeFitController;
use App\Http\Controllers\Auth\CustomerAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/ready', ReadinessController::class)->name('api.ready');

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/catalog/products', [CatalogController::class, 'index'])->name('catalog.products.index');
    Route::get('/catalog/products/{product:slug}', [CatalogController::class, 'show'])->name('catalog.products.show');
    Route::post('/catalog/products/{product:slug}/fit/recommendation', [SizeFitController::class, 'recommend'])->middleware('throttle:30,1')->name('fit.recommend');
    Route::post('/catalog/products/{product:slug}/fit/events', [SizeFitController::class, 'event'])->middleware('throttle:60,1')->name('fit.events');

    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
        Route::get('/auth/me', [CustomerAuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [CustomerAuthController::class, 'logout'])->name('auth.logout');

        Route::get('/customer', [CustomerAccountController::class, 'show'])->name('customer.show');
        Route::put('/customer', [CustomerAccountController::class, 'update'])->name('customer.update');

        Route::get('/customer/addresses', [CustomerAccountController::class, 'addresses'])->name('customer.addresses.index');
        Route::post('/customer/addresses', [CustomerAccountController::class, 'storeAddress'])->name('customer.addresses.store');
        Route::put('/customer/addresses/{address}', [CustomerAccountController::class, 'updateAddress'])->name('customer.addresses.update');
        Route::delete('/customer/addresses/{address}', [CustomerAccountController::class, 'destroyAddress'])->name('customer.addresses.destroy');

        Route::get('/customer/consents', [CustomerAccountController::class, 'consents'])->name('customer.consents.index');
        Route::post('/customer/consents', [CustomerAccountController::class, 'recordConsent'])->name('customer.consents.store');

        Route::get('/customer/export', [CustomerAccountController::class, 'export'])->name('customer.export');
        Route::post('/customer/deletion', [CustomerAccountController::class, 'requestDeletion'])->name('customer.deletion.request');
        Route::delete('/customer/deletion', [CustomerAccountController::class, 'cancelDeletion'])->name('customer.deletion.cancel');
        Route::put('/catalog/products/{product:slug}/fit/feedback', [SizeFitController::class, 'feedback'])->name('fit.feedback');

        Route::post('/customer/otp', [OtpController::class, 'request'])
            ->middleware('throttle:20,1')
            ->name('customer.otp.request');
        Route::post('/customer/otp/verify', [OtpController::class, 'verify'])
            ->middleware('throttle:30,1')
            ->name('customer.otp.verify');
    });
});
