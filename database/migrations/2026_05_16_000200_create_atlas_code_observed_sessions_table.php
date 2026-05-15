<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Code Observed Sessions · canonical persistence.
 *
 * Canon: docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *
 * Stores every interactive observed provider session: state machine, packet
 * ref, prompt hash, scope guard outcome, gates, git snapshot, signed human
 * decision. Services fall back to filesystem JSON when this table is absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_code_observed_sessions')) {
            return;
        }
        Schema::create('atlas_code_observed_sessions', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->uuid('obra_id')->index();
            $table->string('obra_title', 240)->nullable();
            $table->string('work_packet_id', 64)->index();
            $table->string('provider_id', 80)->index();
            $table->string('provider_name', 160)->nullable();
            $table->string('provider_family', 80)->nullable();
            $table->string('invocation_mode', 80)->default('interactive_observed');
            $table->string('role_slot', 80)->nullable();
            $table->string('workspace_slug', 80)->nullable();
            $table->string('workspace_path', 2048)->nullable();
            $table->string('packet_md_path', 2048)->nullable();
            $table->string('packet_md_status', 40)->default('unknown');
            $table->text('packet_md_excerpt')->nullable();
            $table->text('prompt');
            $table->string('prompt_hash', 80)->nullable();
            $table->string('terminal_command_hint', 240)->nullable();
            $table->string('state', 40)->default('waiting_operator')->index();
            $table->jsonb('state_history')->default('[]');
            $table->timestamp('operator_opened_terminal_at')->nullable();
            $table->timestamp('operator_marked_running_at')->nullable();
            $table->timestamp('result_imported_at')->nullable();
            $table->text('report_text')->nullable();
            $table->jsonb('report_files')->default('[]');
            $table->text('diff_excerpt')->nullable();
            $table->string('diff_hash', 80)->nullable();
            $table->jsonb('gates')->default('[]');
            $table->jsonb('gates_summary')->nullable();
            $table->timestamp('gates_evaluated_at')->nullable();
            $table->jsonb('scope_guard')->nullable();
            $table->jsonb('git_snapshot')->nullable();
            $table->jsonb('human_decision')->nullable();
            $table->string('decision_signature', 240)->nullable();
            $table->string('decision_public_key', 240)->nullable();
            $table->string('decision_signing_status', 40)->nullable();
            $table->timestamp('decision_signed_at')->nullable();
            $table->string('blocker_reason', 1000)->nullable();
            $table->jsonb('governance')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_code_observed_sessions');
    }
};
