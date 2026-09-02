<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('blocks');
            $table->string('status')->default('draft')->index();
            $table->string('seo_title', 70);
            $table->string('seo_description', 180);
            $table->string('canonical_path')->unique();
            $table->string('robots')->default('index,follow');
            $table->string('schema_type')->default('WebPage');
            $table->string('sitemap_segment')->default('content')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('content_page_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('content_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->json('before');
            $table->json('after');
            $table->uuid('rollback_of_uuid')->nullable()->index();
            $table->timestamp('created_at');
        });

        Schema::create('seo_route_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('route_key')->unique();
            $table->string('path_pattern');
            $table->string('canonical_policy');
            $table->string('robots_policy');
            $table->string('schema_type')->nullable();
            $table->string('sitemap_segment')->nullable();
            $table->boolean('facets_indexable')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('seo_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('source_path')->unique();
            $table->string('destination_path');
            $table->unsignedSmallInteger('status_code')->default(308);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        $now = now();
        DB::table('seo_route_policies')->insert([
            ['route_key' => 'home', 'path_pattern' => '/', 'canonical_policy' => 'self', 'robots_policy' => 'index,follow', 'schema_type' => 'WebSite', 'sitemap_segment' => 'core', 'facets_indexable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['route_key' => 'catalog', 'path_pattern' => '/products', 'canonical_policy' => 'clean_path', 'robots_policy' => 'index,follow', 'schema_type' => 'CollectionPage', 'sitemap_segment' => 'core', 'facets_indexable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['route_key' => 'product', 'path_pattern' => '/product/{slug}', 'canonical_policy' => 'backend_product', 'robots_policy' => 'index,follow', 'schema_type' => 'Product', 'sitemap_segment' => 'products', 'facets_indexable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['route_key' => 'account', 'path_pattern' => '/account/*', 'canonical_policy' => 'self', 'robots_policy' => 'noindex,nofollow', 'schema_type' => null, 'sitemap_segment' => null, 'facets_indexable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['route_key' => 'checkout', 'path_pattern' => '/checkout', 'canonical_policy' => 'self', 'robots_policy' => 'noindex,nofollow', 'schema_type' => null, 'sitemap_segment' => null, 'facets_indexable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_redirects');
        Schema::dropIfExists('seo_route_policies');
        Schema::dropIfExists('content_page_revisions');
        Schema::dropIfExists('content_pages');
    }
};
