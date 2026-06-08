<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operator_profile_snapshots')) {
            return;
        }

        Schema::create('operator_profile_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operator_id', 120)->index();
            $table->string('snapshot_kind', 80)->index();
            $table->longText('summary');
            $table->string('profile_hash', 64)->index();
            $table->json('included_item_ids')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['operator_id', 'snapshot_kind', 'created_at'], 'idx_operator_snapshots_operator_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_profile_snapshots');
    }
};
