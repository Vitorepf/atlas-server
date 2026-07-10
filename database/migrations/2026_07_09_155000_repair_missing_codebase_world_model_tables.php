<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_codebase_world_models')) {
            Schema::create('ai_codebase_world_models', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.codebase_world_model.v1');
                $table->string('model_id', 120)->unique();
                $table->string('scope', 160)->default('atlas-server')->index();
                $table->string('status', 40)->default('built')->index();
                $table->json('capabilities')->nullable();
                $table->json('risks')->nullable();
                $table->json('receipt')->nullable();
                $table->string('model_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_codebase_world_model_nodes')) {
            Schema::create('ai_codebase_world_model_nodes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('world_model_id')->index();
                $table->string('node_id', 160)->index();
                $table->string('node_type', 80)->index();
                $table->string('path', 500)->nullable()->index();
                $table->string('flow_id', 80)->nullable()->index();
                $table->json('capabilities')->nullable();
                $table->json('risks')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['world_model_id', 'node_id'], 'idx_world_model_node_unique');
            });
        }

        if (! Schema::hasTable('ai_codebase_world_model_edges')) {
            Schema::create('ai_codebase_world_model_edges', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('world_model_id')->index();
                $table->string('from_node_id', 160)->index();
                $table->string('to_node_id', 160)->index();
                $table->string('edge_type', 80)->index();
                $table->json('metadata')->nullable();
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->timestamp('observed_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('stale_after')->nullable();
                $table->string('source_hash', 64)->nullable();
                $table->uuid('superseded_by')->nullable();
                $table->string('authority_level', 40)->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('ai_codebase_world_model_edges', function (Blueprint $table): void {
                foreach ([
                    'valid_from' => 'timestamp',
                    'valid_until' => 'timestamp',
                    'observed_at' => 'timestamp',
                    'verified_at' => 'timestamp',
                    'stale_after' => 'timestamp',
                ] as $column => $type) {
                    if (! Schema::hasColumn('ai_codebase_world_model_edges', $column)) {
                        $table->{$type}($column)->nullable();
                    }
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
        }

        $this->addIndex('ai_codebase_world_model_edges', ['world_model_id', 'from_node_id', 'edge_type'], 'idx_wm_edges_wm_from_type');
        $this->addIndex('ai_codebase_world_model_edges', ['world_model_id', 'to_node_id', 'edge_type'], 'idx_wm_edges_wm_to_type');
        $this->addIndex('ai_codebase_world_model_edges', ['stale_after'], 'ai_codebase_world_model_edges_stale_after_repair_index');
        $this->addIndex('ai_codebase_world_model_edges', ['superseded_by'], 'ai_codebase_world_model_edges_superseded_by_repair_index');
        $this->addIndex('ai_codebase_world_model_edges', ['authority_level'], 'ai_codebase_world_model_edges_authority_level_repair_index');
    }

    public function down(): void
    {
        // Repair migration: never remove a canonical world model.
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndex(string $tableName, array $columns, string $index): void
    {
        try {
            Schema::table($tableName, static function (Blueprint $table) use ($columns, $index): void {
                $table->index($columns, $index);
            });
        } catch (Throwable) {
            // Already present from the original migration chain.
        }
    }
};
