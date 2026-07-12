<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_l2_hierarchical_summaries')) {
            return;
        }

        Schema::create('atlas_l2_hierarchical_summaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('compaction_id')->index();
            $table->uuid('thread_id')->nullable()->index();
            $table->unsignedInteger('version');
            $table->string('status', 20)->index();
            $table->string('local_runtime', 120);
            $table->string('l1_summary_hash', 64)->index();
            $table->string('l2_summary_hash', 64)->nullable()->index();
            $table->text('l2_summary')->nullable();
            $table->decimal('coverage_score', 5, 4);
            $table->decimal('l1_context_retention_score', 5, 4);
            $table->decimal('l2_context_retention_score', 5, 4);
            $table->decimal('compression_ratio', 8, 4)->nullable();
            $table->json('required_items');
            $table->json('scorer_report');
            $table->json('rejection_reasons');
            $table->json('metadata');
            $table->timestamps();

            $table->unique(['compaction_id', 'version'], 'idx_l2_hierarchical_compaction_version');
            $table->index(['compaction_id', 'status', 'version'], 'idx_l2_hierarchical_compaction_status');
            $table->index(['thread_id', 'status', 'created_at'], 'idx_l2_hierarchical_thread_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_l2_hierarchical_summaries');
    }
};
