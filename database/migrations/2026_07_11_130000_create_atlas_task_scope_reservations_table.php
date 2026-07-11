<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_task_scope_reservations')) {
            return;
        }

        Schema::create('atlas_task_scope_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('run_id', 120)->index();
            $table->string('canonical_scope_key', 64)->index();
            $table->text('scope_path');
            $table->string('active_scope_key', 64)->nullable()->unique();
            $table->string('mode', 40);
            $table->string('lease_owner', 160);
            $table->string('lease_token', 160);
            $table->string('authority_hash', 64);
            $table->string('state', 40)->index();
            $table->unsignedBigInteger('fencing_token');
            $table->string('baseline_hash', 64);
            $table->string('idempotency_key', 160)->unique();
            $table->timestampTz('lease_expires_at')->index();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();

            $table->index(
                ['canonical_scope_key', 'fencing_token'],
                'atlas_task_scope_reservations_scope_fence_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_task_scope_reservations');
    }
};
