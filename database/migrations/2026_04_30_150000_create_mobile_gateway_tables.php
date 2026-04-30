<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_mobile_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor')->index();
            $table->string('device_label', 80);
            $table->string('platform', 16);
            $table->string('app_version', 32)->nullable();
            $table->string('os_version', 64)->nullable();
            $table->text('expo_push_token')->nullable();
            $table->string('push_token_hash', 128)->nullable()->index();
            $table->string('device_token_hash', 128)->unique();
            $table->string('notification_permissions', 32)->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('paired_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index('last_seen_at');
        });

        Schema::create('mobile_pairing_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor')->index();
            $table->string('code_hash', 128)->unique();
            $table->string('device_label', 80);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_pairing_codes');
        Schema::dropIfExists('atlas_mobile_devices');
    }
};
