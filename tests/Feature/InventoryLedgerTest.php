<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Services\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_adjustments_are_transactional_append_only_and_idempotent(): void
    {
        $variant = ProductVariant::factory()->create();
        $location = InventoryLocation::factory()->create();
        $ledger = app(InventoryLedger::class);
        $requestId = (string) Str::uuid();

        $first = $ledger->adjust($variant, $location, 5, 'Initial stock', $requestId);
        $replayed = $ledger->adjust($variant, $location, 5, 'Initial stock', $requestId);
        $ledger->adjust($variant, $location, -2, 'Cycle count correction', (string) Str::uuid());

        $this->assertSame($first->getKey(), $replayed->getKey());
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseHas('inventory_balances', [
            'product_variant_id' => $variant->getKey(),
            'inventory_location_id' => $location->getKey(),
            'on_hand' => 3,
            'reserved' => 0,
        ]);
    }

    public function test_inventory_cannot_be_made_negative_or_changed_outside_the_ledger(): void
    {
        $variant = ProductVariant::factory()->create();
        $location = InventoryLocation::factory()->create();
        $ledger = app(InventoryLedger::class);
        $ledger->adjust($variant, $location, 2, 'Initial stock');

        try {
            $ledger->adjust($variant, $location, -3, 'Invalid correction');
            $this->fail('Negative inventory mutation should have failed.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('inventory_balances', ['on_hand' => 2]);
        }

        $balance = InventoryBalance::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $balance->forceFill(['on_hand' => 99])->save();
    }

    public function test_inventory_movements_cannot_be_rewritten(): void
    {
        $variant = ProductVariant::factory()->create();
        $location = InventoryLocation::factory()->create();
        $movement = app(InventoryLedger::class)->adjust($variant, $location, 1, 'Initial stock');

        $this->expectException(LogicException::class);
        InventoryMovement::query()->findOrFail($movement->getKey())->forceFill(['delta' => 9])->save();
    }
}
