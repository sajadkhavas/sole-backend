<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('description')->index();
            $table->string('colorway')->nullable()->after('brand');
            $table->json('tags')->nullable()->after('colorway');
        });

        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending_upload')->index();
            $table->string('original_filename')->nullable();
            $table->string('declared_mime', 120)->nullable();
            $table->string('detected_mime', 120)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedSmallInteger('frame_count')->nullable();
            $table->char('sha256', 64)->nullable()->index();
            $table->string('quarantine_disk');
            $table->string('quarantine_path');
            $table->string('source_disk')->nullable();
            $table->string('source_path')->nullable();
            $table->decimal('focal_x', 5, 4)->default(0.5000);
            $table->decimal('focal_y', 5, 4)->default(0.5000);
            $table->text('alt_text')->nullable();
            $table->string('rejection_code', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();
        });

        Schema::create('media_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->string('recipe', 48);
            $table->unsignedInteger('recipe_version');
            $table->string('format', 16)->default('webp');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->string('disk');
            $table->string('path');
            $table->timestamps();
            $table->unique(['media_asset_id', 'recipe', 'recipe_version'], 'media_variant_recipe_unique');
        });

        Schema::create('media_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('role', 32);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('alt_text')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id'], 'media_attachment_subject_index');
            $table->unique(['media_asset_id', 'subject_type', 'subject_id', 'role', 'sort_order'], 'media_attachment_unique');
        });

        Schema::create('catalog_import_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->char('manifest_sha256', 64)->unique();
            $table->unsignedInteger('manifest_version');
            $table->string('source')->nullable();
            $table->string('status', 24)->index();
            $table->json('report');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_runs');
        Schema::dropIfExists('media_attachments');
        Schema::dropIfExists('media_variants');
        Schema::dropIfExists('media_assets');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['brand', 'colorway', 'tags']);
        });
    }
};
