<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hermes_hook_registrations')) {
            return;
        }

        Schema::create('hermes_hook_registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_id', 80)->index();
            $table->string('mission_hash', 64)->nullable()->index();
            $table->json('events_json')->default('[]');
            $table->string('consent_basis', 80)->nullable();
            $table->string('hermes_home_hash', 64)->nullable()->index();
            $table->string('receipt_hash', 64)->nullable()->index();
            $table->boolean('active')->default(false)->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->index(['trace_id', 'active'], 'idx_hermes_hook_reg_trace_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hermes_hook_registrations');
    }
};
