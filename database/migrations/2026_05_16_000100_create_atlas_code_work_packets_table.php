<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Code Work Packets · canonical persistence.
 *
 * Canon: docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *
 * Replaces (and coexists with) the filesystem JSON storage. Services detect
 * `Schema::hasTable('atlas_code_work_packets')` at runtime and switch to DB
 * when available, falling back to filesystem otherwise — so existing tests
 * that bootstrap a minimal sqlite schema keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_code_work_packets')) {
            return;
        }
        Schema::create('atlas_code_work_packets', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->uuid('obra_id')->index();
            $table->string('obra_title', 240)->nullable();
            $table->string('workspace_slug', 80)->nullable()->index();
            $table->string('workspace_path', 2048)->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->text('objective');
            $table->text('context_summary')->nullable();
            $table->jsonb('allowed_files')->default('[]');
            $table->jsonb('forbidden_files')->default('[]');
            $table->jsonb('interfaces')->default('[]');
            $table->jsonb('constraints')->default('[]');
            $table->jsonb('acceptance_criteria')->default('[]');
            $table->jsonb('verification_commands')->default('[]');
            $table->jsonb('evidence_required')->default('[]');
            $table->text('report_format')->nullable();
            $table->text('stop_rule')->nullable();
            $table->string('role_slot', 80)->default('implementation_lead');
            $table->string('risk_band', 40)->default('medium');
            $table->string('task_category', 80)->default('feature');
            $table->timestamp('exported_at')->nullable();
            $table->string('packet_md_path', 2048)->nullable();
            $table->string('prompt_hash', 80)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_code_work_packets');
    }
};
