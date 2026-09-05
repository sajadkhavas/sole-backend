<?php

namespace App\Providers;

use App\Contracts\MediaMalwareScanner;
use App\Contracts\NotificationChannelAdapter;
use App\Contracts\OtpSender;
use App\Contracts\PaymentGateway;
use App\Contracts\ShippingProvider;
use App\Models\AnalyticsFunnelSnapshot;
use App\Models\AuditLog;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Collection;
use App\Models\ContentPage;
use App\Models\Experiment;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\LoyaltyLedgerEntry;
use App\Models\NotificationDeliveryAttempt;
use App\Models\ObservabilityErrorEvent;
use App\Models\ObservabilityRequestMetric;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliation;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\RefundRequest;
use App\Models\ReturnRequest;
use App\Models\SeoRoutePolicy;
use App\Models\Shipment;
use App\Models\SizeGuide;
use App\Models\SupportCase;
use App\Models\User;
use App\Observers\AuditableObserver;
use App\Policies\AuditLogPolicy;
use App\Policies\BusinessSettingPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\CollectionPolicy;
use App\Policies\ContentPagePolicy;
use App\Policies\ExperimentPolicy;
use App\Policies\InventoryLocationPolicy;
use App\Policies\InventoryMovementPolicy;
use App\Policies\LoyaltyLedgerEntryPolicy;
use App\Policies\NotificationDeliveryAttemptPolicy;
use App\Policies\ObservabilityReadPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentAttemptPolicy;
use App\Policies\PaymentReconciliationPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProductReviewPolicy;
use App\Policies\ProductVariantPolicy;
use App\Policies\RefundRequestPolicy;
use App\Policies\ReturnRequestPolicy;
use App\Policies\SeoRoutePolicyPolicy;
use App\Policies\ShipmentPolicy;
use App\Policies\SupportCasePolicy;
use App\Policies\UserPolicy;
use App\Services\Auth\KavenegarOtpSender;
use App\Services\Commerce\ConfiguredShippingProvider;
use App\Services\Commerce\DisabledPaymentGateway;
use App\Services\Commerce\ZarinPalPaymentGateway;
use App\Services\Engagement\DisabledNotificationChannelAdapter;
use App\Services\Media\ClamAvMediaMalwareScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MediaMalwareScanner::class, ClamAvMediaMalwareScanner::class);
        $this->app->bind(OtpSender::class, KavenegarOtpSender::class);
        $this->app->singleton(NotificationChannelAdapter::class, DisabledNotificationChannelAdapter::class);
        $this->app->singleton(PaymentGateway::class, function ($app): PaymentGateway {
            return match ((string) config('commerce.payment.provider', 'disabled')) {
                'disabled' => $app->make(DisabledPaymentGateway::class),
                'zarinpal' => $app->make(ZarinPalPaymentGateway::class),
                default => throw new RuntimeException('Unsupported payment provider configuration.'),
            };
        });
        $this->app->singleton(ShippingProvider::class, function ($app): ShippingProvider {
            return match ((string) config('commerce.shipping.provider', 'configured')) {
                'configured' => $app->make(ConfiguredShippingProvider::class),
                default => throw new RuntimeException('Unsupported shipping provider configuration.'),
            };
        });
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());
        $this->configureP07RateLimiters();
        $this->configureP11RateLimiters();

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Collection::class, CollectionPolicy::class);
        Gate::policy(ContentPage::class, ContentPagePolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductVariant::class, ProductVariantPolicy::class);
        Gate::policy(SizeGuide::class, ProductPolicy::class);
        Gate::policy(SeoRoutePolicy::class, SeoRoutePolicyPolicy::class);
        Gate::policy(InventoryLocation::class, InventoryLocationPolicy::class);
        Gate::policy(InventoryMovement::class, InventoryMovementPolicy::class);
        Gate::policy(BusinessSetting::class, BusinessSettingPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(ObservabilityRequestMetric::class, ObservabilityReadPolicy::class);
        Gate::policy(ObservabilityErrorEvent::class, ObservabilityReadPolicy::class);
        Gate::policy(AnalyticsFunnelSnapshot::class, ObservabilityReadPolicy::class);
        Gate::policy(Experiment::class, ExperimentPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(PaymentAttempt::class, PaymentAttemptPolicy::class);
        Gate::policy(PaymentReconciliation::class, PaymentReconciliationPolicy::class);
        Gate::policy(Shipment::class, ShipmentPolicy::class);
        Gate::policy(ReturnRequest::class, ReturnRequestPolicy::class);
        Gate::policy(RefundRequest::class, RefundRequestPolicy::class);
        Gate::policy(SupportCase::class, SupportCasePolicy::class);
        Gate::policy(ProductReview::class, ProductReviewPolicy::class);
        Gate::policy(NotificationDeliveryAttempt::class, NotificationDeliveryAttemptPolicy::class);
        Gate::policy(LoyaltyLedgerEntry::class, LoyaltyLedgerEntryPolicy::class);

        foreach ([User::class, Category::class, Collection::class, ContentPage::class, Product::class, ProductVariant::class,
            SizeGuide::class, SeoRoutePolicy::class, InventoryLocation::class, BusinessSetting::class, Experiment::class] as $model) {
            $model::observe(AuditableObserver::class);
        }
    }

    private function configureP07RateLimiters(): void
    {
        $authenticatedKey = fn (Request $request, string $action): string => $action.':'.($request->user()?->getAuthIdentifier() ?? $request->ip());
        RateLimiter::for('p07-shipping-quotes', fn (Request $request): Limit => Limit::perMinute(30)->by($authenticatedKey($request, 'shipping-quotes')));
        RateLimiter::for('p07-checkout', fn (Request $request): Limit => Limit::perMinute(20)->by($authenticatedKey($request, 'checkout')));
        RateLimiter::for('p07-payment-start', fn (Request $request): Limit => Limit::perMinute(20)->by($authenticatedKey($request, 'payment-start')));
        RateLimiter::for('p07-payment-verify', fn (Request $request): Limit => Limit::perMinute(30)->by($authenticatedKey($request, 'payment-verify')));
        RateLimiter::for('p07-payment-reconcile', fn (Request $request): Limit => Limit::perMinute(10)->by($authenticatedKey($request, 'payment-reconcile')));
        RateLimiter::for('p07-return', fn (Request $request): Limit => Limit::perMinute(10)->by($authenticatedKey($request, 'return')));
        RateLimiter::for('p07-refund', fn (Request $request): Limit => Limit::perMinute(10)->by($authenticatedKey($request, 'refund')));
        RateLimiter::for('p07-shipping-webhook', fn (Request $request): Limit => Limit::perMinute(120)->by('shipping-webhook:'.$request->ip()));
    }

    private function configureP11RateLimiters(): void
    {
        $userKey = fn (Request $request, string $action): string => $action.':'.($request->user()?->getAuthIdentifier() ?? 'anonymous');
        RateLimiter::for('p11-analytics', fn (Request $request): Limit => Limit::perMinute(120)->by($userKey($request, 'analytics')));
        RateLimiter::for('p11-experiments', fn (Request $request): Limit => Limit::perMinute(60)->by($userKey($request, 'experiments')));
    }
}
