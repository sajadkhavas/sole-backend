<?php

namespace App\Providers;

use App\Contracts\MediaMalwareScanner;
use App\Contracts\OtpSender;
use App\Models\AuditLog;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Collection;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SizeGuide;
use App\Models\User;
use App\Observers\AuditableObserver;
use App\Policies\AuditLogPolicy;
use App\Policies\BusinessSettingPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\CollectionPolicy;
use App\Policies\InventoryLocationPolicy;
use App\Policies\InventoryMovementPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProductVariantPolicy;
use App\Policies\UserPolicy;
use App\Services\Auth\KavenegarOtpSender;
use App\Services\Media\ClamAvMediaMalwareScanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MediaMalwareScanner::class, ClamAvMediaMalwareScanner::class);
        $this->app->bind(OtpSender::class, KavenegarOtpSender::class);
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Collection::class, CollectionPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductVariant::class, ProductVariantPolicy::class);
        Gate::policy(SizeGuide::class, ProductPolicy::class);
        Gate::policy(InventoryLocation::class, InventoryLocationPolicy::class);
        Gate::policy(InventoryMovement::class, InventoryMovementPolicy::class);
        Gate::policy(BusinessSetting::class, BusinessSettingPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        foreach ([
            User::class,
            Category::class,
            Collection::class,
            Product::class,
            ProductVariant::class,
            SizeGuide::class,
            InventoryLocation::class,
            BusinessSetting::class,
        ] as $model) {
            $model::observe(AuditableObserver::class);
        }
    }
}
