<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('shipping_provider', 40)->nullable()->after('shipping_minor');
            $table->string('shipping_service_code', 80)->nullable()->after('shipping_provider');
        });
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->timestamp('committed_at')->nullable()->after('released_at');
        });

        Schema::create('shipping_quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_address_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('service_code', 80);
            $table->string('label', 120);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedSmallInteger('eta_min_days')->nullable();
            $table->unsignedSmallInteger('eta_max_days')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'cart_id', 'expires_at'], 'shipping_quote_owner_cart_expiry');
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->uuid('idempotency_key')->unique();
            $table->char('request_fingerprint', 64);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 32)->default('initiating')->index();
            $table->string('authority', 120)->nullable()->index();
            $table->string('reference_id', 120)->nullable()->index();
            $table->string('provider_code', 40)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status'], 'payment_order_status_index');
        });

        Schema::create('payment_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('expected_status', 40);
            $table->string('observed_status', 40);
            $table->string('outcome', 40)->index();
            $table->char('payload_hash', 64)->nullable();
            $table->timestamp('reconciled_at');
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('service_code', 80);
            $table->string('status', 32)->default('pending')->index();
            $table->string('tracking_number', 160)->nullable()->index();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shipment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 120);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('reason', 160);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['shipment_id', 'event_key'], 'shipment_event_key_unique');
        });

        Schema::create('return_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('requested')->index();
            $table->string('reason', 80);
            $table->text('reason_text')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique('order_id');
        });

        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_attempt_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 80);
            $table->string('status', 32)->default('requested')->index();
            $table->string('provider_reference', 160)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status'], 'refund_order_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('return_requests');
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('payment_reconciliations');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('shipping_quotes');

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropColumn('committed_at');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['shipping_provider', 'shipping_service_code']);
        });
    }
};
