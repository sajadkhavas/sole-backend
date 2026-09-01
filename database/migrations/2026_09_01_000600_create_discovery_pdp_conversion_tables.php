<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->smallInteger('merchandising_priority')->default(0)->index();
        });

        Schema::create('back_in_stock_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->char('email_hash', 64)->index();
            $table->text('contact_email');
            $table->string('consent_version', 32);
            $table->timestamp('consent_granted_at');
            $table->string('source', 32)->default('pdp');
            $table->string('status', 24)->default('pending')->index();
            $table->timestamps();
            $table->unique(['product_variant_id', 'email_hash'], 'back_in_stock_variant_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('back_in_stock_intents');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('merchandising_priority');
        });
    }
};
