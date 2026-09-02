<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('price_anchor_minor');
            $table->timestamps();
            $table->unique(['user_id', 'product_variant_id']);
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('channel', 16);
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('daily_cap')->default(1);
            $table->time('quiet_start')->nullable();
            $table->time('quiet_end')->nullable();
            $table->string('timezone', 64)->default('Asia/Tehran');
            $table->timestamps();
            $table->unique(['user_id', 'channel']);
        });

        Schema::create('notification_signals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 48);
            $table->string('source_type', 48);
            $table->unsignedBigInteger('source_id');
            $table->string('idempotency_key', 160)->unique();
            $table->json('facts');
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('eligible_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('notification_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_signal_id')->constrained()->restrictOnDelete();
            $table->string('attempt_key', 190)->unique();
            $table->string('channel', 16);
            $table->string('provider', 48)->nullable();
            $table->string('status', 24)->default('blocked');
            $table->string('reason', 120);
            $table->char('response_hash', 64)->nullable();
            $table->timestamp('attempted_at');
            $table->index(['channel', 'status', 'attempted_at']);
        });

        Schema::create('loyalty_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 24);
            $table->bigInteger('points_delta');
            $table->string('idempotency_key', 160);
            $table->string('reason', 120);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'expires_at']);
        });

        Schema::table('back_in_stock_intents', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->char('unsubscribe_token_hash', 64)->nullable()->after('status');
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('last_signalled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('back_in_stock_intents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['unsubscribe_token_hash', 'unsubscribed_at', 'last_signalled_at']);
        });
        Schema::dropIfExists('loyalty_ledger_entries');
        Schema::dropIfExists('notification_delivery_attempts');
        Schema::dropIfExists('notification_signals');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('customer_wishlist_items');
    }
};
