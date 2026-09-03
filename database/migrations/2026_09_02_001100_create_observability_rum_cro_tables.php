<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observability_request_metrics', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('bucket_started_at');
            $table->string('route_name', 160);
            $table->string('method', 12);
            $table->string('status_class', 3);
            $table->unsignedBigInteger('request_count')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);
            $table->decimal('duration_sum_ms', 18, 3)->default(0);
            $table->decimal('duration_max_ms', 12, 3)->default(0);
            $table->unsignedBigInteger('duration_le_100_ms')->default(0);
            $table->unsignedBigInteger('duration_le_250_ms')->default(0);
            $table->unsignedBigInteger('duration_le_500_ms')->default(0);
            $table->unsignedBigInteger('duration_le_1000_ms')->default(0);
            $table->unsignedBigInteger('duration_le_2500_ms')->default(0);
            $table->unsignedBigInteger('duration_le_5000_ms')->default(0);
            $table->unsignedBigInteger('duration_gt_5000_ms')->default(0);
            $table->timestamps();
            $table->unique(['bucket_started_at', 'route_name', 'method', 'status_class'], 'observability_request_metrics_bucket_unique');
            $table->index(['bucket_started_at', 'status_class'], 'obs_metrics_bucket_status_idx');
        });

        Schema::create('observability_error_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->index();
            $table->char('trace_id', 32)->index();
            $table->char('span_id', 16);
            $table->string('route_name', 160);
            $table->string('method', 12);
            $table->unsignedSmallInteger('status_code');
            $table->string('exception_class', 255);
            $table->char('fingerprint', 64)->index();
            $table->timestamp('occurred_at')->index();
        });

        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('session_id')->index();
            $table->unsignedSmallInteger('taxonomy_version');
            $table->string('event_name', 64)->index();
            $table->string('route_name', 64)->index();
            $table->json('properties')->nullable();
            $table->char('trace_id', 32)->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('received_at')->index();
            $table->index(['taxonomy_version', 'event_name', 'occurred_at'], 'analytics_events_taxonomy_event_time');
        });

        Schema::create('analytics_funnel_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date');
            $table->unsignedSmallInteger('taxonomy_version');
            $table->unsignedBigInteger('catalog_sessions')->default(0);
            $table->unsignedBigInteger('product_sessions')->default(0);
            $table->unsignedBigInteger('cart_sessions')->default(0);
            $table->unsignedBigInteger('checkout_sessions')->default(0);
            $table->unsignedBigInteger('order_sessions')->default(0);
            $table->unsignedBigInteger('paid_sessions')->default(0);
            $table->timestamp('rebuilt_at');
            $table->timestamps();
            $table->unique(['snapshot_date', 'taxonomy_version'], 'analytics_funnel_snapshot_unique');
        });

        Schema::create('experiments', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft')->index();
            $table->string('surface', 64);
            $table->string('hypothesis', 500);
            $table->string('primary_metric', 80);
            $table->json('guardrail_metrics');
            $table->json('variants');
            $table->json('allocation_basis_points');
            $table->unsignedInteger('minimum_sample_size');
            $table->string('rollback_plan', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('stops_at')->nullable();
            $table->timestamps();
            $table->unique(['key', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiments');
        Schema::dropIfExists('analytics_funnel_snapshots');
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('observability_error_events');
        Schema::dropIfExists('observability_request_metrics');
    }
};
