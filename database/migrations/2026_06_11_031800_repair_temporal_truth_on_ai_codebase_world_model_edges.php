<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_codebase_world_model_edges')) {
            return;
        }

        Schema::table('ai_codebase_world_model_edges', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'valid_from')) {
                $table->timestamp('valid_from')->nullable();
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'valid_until')) {
                $table->timestamp('valid_until')->nullable();
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'observed_at')) {
                $table->timestamp('observed_at')->nullable();
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'verified_at')) {
                $table->timestamp('verified_at')->nullable();
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'stale_after')) {
                $table->timestamp('stale_after')->nullable();
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'source_hash')) {
                $table->string('source_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'superseded_by')) {
                $table->uuid('superseded_by')->nullable();
            }
            if (! Schema::hasColumn('ai_codebase_world_model_edges', 'authority_level')) {
                $table->string('authority_level', 40)->nullable();
            }
        });

        Schema::table('ai_codebase_world_model_edges', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_codebase_world_model_edges', 'stale_after')) {
                $table->index('stale_after', 'ai_codebase_world_model_edges_stale_after_repair_index');
            }
            if (Schema::hasColumn('ai_codebase_world_model_edges', 'superseded_by')) {
                $table->index('superseded_by', 'ai_codebase_world_model_edges_superseded_by_repair_index');
            }
            if (Schema::hasColumn('ai_codebase_world_model_edges', 'authority_level')) {
                $table->index('authority_level', 'ai_codebase_world_model_edges_authority_level_repair_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_codebase_world_model_edges')) {
            return;
        }

        Schema::table('ai_codebase_world_model_edges', function (Blueprint $table): void {
            foreach ([
                'ai_codebase_world_model_edges_stale_after_repair_index',
                'ai_codebase_world_model_edges_superseded_by_repair_index',
                'ai_codebase_world_model_edges_authority_level_repair_index',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (Throwable) {
                    // Best-effort rollback for drifted local schemas.
                }
            }
        });
    }
};
