<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_publication_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->json('before');
            $table->json('after');
            $table->uuid('rollback_of_uuid')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'action', 'created_at'], 'product_publication_revision_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_publication_revisions');
    }
};
