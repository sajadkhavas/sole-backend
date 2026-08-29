<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seeder_never_creates_product_or_administrator_truth(): void
    {
        $this->seed();

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_storefront_contract_and_operator_commands_are_registered(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->all();

        $this->assertContains('api/ready', $routes);
        $this->assertContains('api/v1/catalog/products', $routes);
        $this->assertContains('api/v1/catalog/products/{product}', $routes);

        $commands = array_keys($this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all());
        $this->assertContains('sole:rbac:sync', $commands);
        $this->assertContains('sole:admin:create', $commands);
        $this->assertContains('sole:admin:grant', $commands);
        $this->assertContains('sole:inventory:adjust', $commands);
    }
}
