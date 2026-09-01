<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('size_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('draft')->index();
            $table->string('source_label', 160);
            $table->text('source_url')->nullable();
            $table->string('measurement_unit', 8)->default('mm');
            $table->string('width_profile', 24)->default('standard');
            $table->text('notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('size_guide_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('size_guide_id')->constrained()->cascadeOnDelete();
            $table->decimal('eu_size', 4, 1);
            $table->unsignedSmallInteger('foot_length_min_mm');
            $table->unsignedSmallInteger('foot_length_max_mm');
            $table->string('label', 80)->nullable();
            $table->timestamps();
            $table->unique(['size_guide_id', 'eu_size']);
        });

        Schema::create('fit_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purchased_size', 16);
            $table->string('overall_fit', 16)->index();
            $table->string('width_fit', 16)->nullable();
            $table->string('source', 24)->default('customer');
            $table->timestamps();
            $table->unique(['user_id', 'product_id', 'product_variant_id'], 'fit_feedback_customer_product_unique');
        });

        Schema::create('fit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('event_name', 40)->index();
            $table->string('confidence_bucket', 16)->nullable();
            $table->string('recommended_size', 16)->nullable();
            $table->uuid('request_id')->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'event_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fit_events');
        Schema::dropIfExists('fit_feedback');
        Schema::dropIfExists('size_guide_entries');
        Schema::dropIfExists('size_guides');
    }
};
