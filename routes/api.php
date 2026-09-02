<?php

use App\Http\Controllers\Api\V1\BackInStockController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CommerceController;
use App\Http\Controllers\Api\V1\CommerceLifecycleController;
use App\Http\Controllers\Api\V1\CustomerAccountController;
use App\Http\Controllers\Api\V1\EngagementController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\ReadinessController;
use App\Http\Controllers\Api\V1\SizeFitController;
use App\Http\Controllers\Api\V1\TrustSupportController;
use App\Http\Controllers\Auth\CustomerAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/ready', ReadinessController::class)->name('api.ready');

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/catalog/products', [CatalogController::class, 'index'])->name('catalog.products.index');
    Route::get('/catalog/products/{product:slug}', [CatalogController::class, 'show'])->name('catalog.products.show');
    Route::get('/catalog/products/{product:slug}/related', [CatalogController::class, 'related'])->name('catalog.products.related');
    Route::post('/catalog/products/{product:slug}/back-in-stock', [BackInStockController::class, 'store'])->middleware('throttle:10,1')->name('catalog.products.back-in-stock');
    Route::delete('/catalog/back-in-stock/{intent}', [BackInStockController::class, 'destroy'])->middleware('throttle:10,1')->name('catalog.back-in-stock.destroy');
    Route::post('/catalog/products/{product:slug}/fit/recommendation', [SizeFitController::class, 'recommend'])->middleware('throttle:30,1')->name('fit.recommend');
    Route::post('/catalog/products/{product:slug}/fit/events', [SizeFitController::class, 'event'])->middleware('throttle:60,1')->name('fit.events');
    Route::get('/commerce/cart', [CommerceController::class, 'cart'])->middleware('throttle:120,1')->name('commerce.cart.show');
    Route::put('/commerce/cart/items/{variant}', [CommerceController::class, 'putItem'])->middleware('throttle:120,1')->name('commerce.cart.items.put');
    Route::delete('/commerce/cart/items/{variant}', [CommerceController::class, 'deleteItem'])->middleware('throttle:120,1')->name('commerce.cart.items.delete');
    Route::post('/commerce/shipping/provider-events', [CommerceLifecycleController::class, 'shippingWebhook'])
        ->middleware('throttle:p07-shipping-webhook')
        ->name('commerce.shipping.provider-events');
    Route::get('/trust/content', [TrustSupportController::class, 'content'])->name('trust.content');

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

        Route::get('/customer/wishlist', [EngagementController::class, 'wishlist'])->name('customer.wishlist.index');
        Route::put('/customer/wishlist/{variant}', [EngagementController::class, 'addWishlist'])->name('customer.wishlist.store');
        Route::delete('/customer/wishlist/{variant}', [EngagementController::class, 'removeWishlist'])->name('customer.wishlist.destroy');
        Route::post('/customer/wishlist/migrate', [EngagementController::class, 'migrateWishlist'])->name('customer.wishlist.migrate');
        Route::get('/customer/notification-preferences', [EngagementController::class, 'preferences'])->name('customer.notifications.preferences.index');
        Route::put('/customer/notification-preferences/{channel}', [EngagementController::class, 'updatePreference'])->name('customer.notifications.preferences.update');
        Route::delete('/customer/notification-preferences/{channel}', [EngagementController::class, 'unsubscribe'])->name('customer.notifications.preferences.unsubscribe');
        Route::get('/customer/notification-signals', [EngagementController::class, 'signals'])->name('customer.notifications.signals.index');
        Route::get('/customer/loyalty', [EngagementController::class, 'loyalty'])->name('customer.loyalty.show');

        Route::post('/commerce/shipping/quotes', [CommerceLifecycleController::class, 'shippingQuotes'])->middleware('throttle:p07-shipping-quotes')->name('commerce.shipping.quotes');
        Route::post('/commerce/checkout', [CommerceController::class, 'checkout'])->middleware('throttle:p07-checkout')->name('commerce.checkout.create');
        Route::get('/commerce/orders', [CommerceController::class, 'orders'])->name('commerce.orders.index');
        Route::get('/commerce/orders/{order}', [CommerceController::class, 'order'])->name('commerce.orders.show');
        Route::post('/commerce/orders/{order}/payments', [CommerceLifecycleController::class, 'initiatePayment'])->middleware('throttle:p07-payment-start')->name('commerce.payments.initiate');
        Route::post('/commerce/payments/{payment}/verify', [CommerceLifecycleController::class, 'verifyPayment'])->middleware('throttle:p07-payment-verify')->name('commerce.payments.verify');
        Route::post('/commerce/payments/{payment}/reconcile', [CommerceLifecycleController::class, 'reconcilePayment'])->middleware('throttle:p07-payment-reconcile')->name('commerce.payments.reconcile');
        Route::post('/commerce/orders/{order}/returns', [CommerceLifecycleController::class, 'requestReturn'])->middleware('throttle:p07-return')->name('commerce.returns.request');
        Route::post('/commerce/orders/{order}/refunds', [CommerceLifecycleController::class, 'requestRefund'])->middleware('throttle:p07-refund')->name('commerce.refunds.request');
        Route::get('/commerce/orders/{order}/tracking', [TrustSupportController::class, 'tracking'])->name('commerce.orders.tracking');
        Route::get('/support/cases', [TrustSupportController::class, 'cases'])->name('support.cases.index');
        Route::post('/support/cases', [TrustSupportController::class, 'createCase'])->middleware('throttle:20,1')->name('support.cases.store');
        Route::get('/support/cases/{case}', [TrustSupportController::class, 'case'])->name('support.cases.show');
        Route::post('/support/cases/{case}/messages', [TrustSupportController::class, 'message'])->middleware('throttle:30,1')->name('support.cases.messages.store');
        Route::get('/communications', [TrustSupportController::class, 'messages'])->name('communications.index');
        Route::post('/reviews', [TrustSupportController::class, 'review'])->middleware('throttle:10,1')->name('reviews.store');

        Route::post('/customer/otp', [OtpController::class, 'request'])
            ->middleware('throttle:20,1')
            ->name('customer.otp.request');
        Route::post('/customer/otp/verify', [OtpController::class, 'verify'])
            ->middleware('throttle:30,1')
            ->name('customer.otp.verify');
    });
});
