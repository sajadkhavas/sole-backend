<?php

use App\Http\Controllers\Admin\MediaUploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->prefix('admin-api/media')->name('admin.media.')->group(function (): void {
    Route::post('/upload-intents', [MediaUploadController::class, 'store'])->name('upload-intents.store');
    Route::post('/{mediaAsset:uuid}/complete', [MediaUploadController::class, 'complete'])->name('complete');
});
