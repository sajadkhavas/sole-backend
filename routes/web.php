<?php

use App\Http\Controllers\Admin\MediaUploadController;
use App\Http\Controllers\Auth\CustomerAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('throttle:20,1')->group(function (): void {
    Route::get('/auth/google/redirect', [CustomerAuthController::class, 'redirect'])
        ->name('customer.auth.google.redirect');
    Route::get('/auth/google/callback', [CustomerAuthController::class, 'callback'])
        ->name('customer.auth.google.callback');
});

Route::middleware('auth')->prefix('admin-api/media')->name('admin.media.')->group(function (): void {
    Route::post('/upload-intents', [MediaUploadController::class, 'store'])->name('upload-intents.store');
    Route::post('/{mediaAsset:uuid}/complete', [MediaUploadController::class, 'complete'])->name('complete');
});
