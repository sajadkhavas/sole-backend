<?php

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('/ready', ReadinessController::class)->name('api.ready');

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/catalog/products', [CatalogController::class, 'index'])->name('catalog.products.index');
    Route::get('/catalog/products/{product:slug}', [CatalogController::class, 'show'])->name('catalog.products.show');
});
