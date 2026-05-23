<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_workspace_artifact_lake_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('runtime_hash', 64)->index();
            $table->string('artifact_hash', 64)->index();
            $table->string('artifact_type', 120)->index();
            $table->string('status', 40)->index();
            $table->string('consumer', 120)->nullable()->index();
            $table->json('source_hashes');
            $table->json('body');
            $table->decimal('quality_score', 5, 2)->default(0);
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->unique(['runtime_hash', 'artifact_hash']);
            $table->index(['workspace_id', 'artifact_type']);
        });

        Schema::create('atlas_workspace_artifact_graph_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('runtime_hash', 64)->unique();
            $table->string('artifact_intelligence_hash', 64)->unique();
            $table->string('status', 40)->index();
            $table->string('lake_hash', 64)->nullable()->index();
            $table->string('graph_hash', 64)->nullable()->index();
            $table->unsignedInteger('artifact_count')->default(0);
            $table->unsignedInteger('node_count')->default(0);
            $table->unsignedInteger('edge_count')->default(0);
            $table->boolean('replay_ready')->default(false)->index();
            $table->string('simulation_decision', 40)->index();
            $table->json('nodes');
            $table->json('edges');
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->index(['workspace_id', 'captured_at']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_workspace_artifact_graph_snapshots');
        Schema::dropIfExists('atlas_workspace_artifact_lake_entries');
    }
};
