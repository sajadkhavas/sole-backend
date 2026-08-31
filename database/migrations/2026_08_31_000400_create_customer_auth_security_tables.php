<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_status', 32)->default('active')->after('last_login_at')->index();
            $table->timestamp('deletion_requested_at')->nullable()->after('account_status');
        });

        Schema::create('auth_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_subject', 191);
            $table->string('email_at_link')->nullable();
            $table->text('avatar_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_subject'], 'auth_identity_provider_subject_unique');
            $table->index(['user_id', 'provider'], 'auth_identity_user_provider_index');
        });

        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone_e164', 16)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('locale', 12)->nullable();
            $table->timestamps();
        });

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 80)->nullable();
            $table->string('recipient_name', 120);
            $table->string('phone_e164', 16);
            $table->string('country_code', 2)->default('IR');
            $table->string('province', 120);
            $table->string('city', 120);
            $table->string('postal_code', 20)->nullable();
            $table->string('address_line1', 255);
            $table->string('address_line2', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'is_default'], 'customer_address_default_index');
        });

        Schema::create('consent_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64)->index();
            $table->boolean('granted');
            $table->string('policy_version', 64);
            $table->string('source', 64)->default('account');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->index(['user_id', 'type', 'occurred_at'], 'consent_user_type_time_index');
        });

        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone_e164', 16)->index();
            $table->string('purpose', 32)->default('verify_phone');
            $table->string('code_digest', 64);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at')->index();
            $table->timestamp('resend_available_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('request_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose', 'created_at'], 'otp_user_purpose_created_index');
        });

        Schema::create('account_lifecycle_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 32)->default('requested')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'type', 'status'], 'lifecycle_user_type_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_lifecycle_requests');
        Schema::dropIfExists('otp_challenges');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_profiles');
        Schema::dropIfExists('auth_identities');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['account_status', 'deletion_requested_at']);
        });
    }
};
