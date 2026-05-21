<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAverTables
{
    protected function createAverTables(): void
    {
        $this->dropAverTables();

        Schema::create('atlas_aver_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('maturity_level', 120)->index();
            $table->uuid('aweos_execution_id')->nullable()->index();
            $table->string('surface_id', 120)->nullable()->index();
            $table->string('domain', 80)->nullable()->index();
            $table->string('flow_id', 120)->nullable()->index();
            $table->string('workspace_hash', 64)->nullable()->index();
            $table->string('objective_hash', 64)->index();
            $table->text('objective');
            $table->json('execution_contract');
            $table->json('patch_plan');
            $table->json('safety_gate');
            $table->json('verification_plan');
            $table->json('rollback_plan');
            $table->json('claim_policy');
            $table->json('evidence_refs')->nullable();
            $table->string('execution_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aver_command_ledgers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('execution_id')->index();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('command_hash', 64)->index();
            $table->string('cwd_hash', 64)->nullable()->index();
            $table->integer('exit_code')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('stdout_excerpt')->nullable();
            $table->text('stderr_excerpt')->nullable();
            $table->string('stdout_hash', 64)->nullable();
            $table->string('stderr_hash', 64)->nullable();
            $table->json('safety_gate');
            $table->json('evidence_refs')->nullable();
            $table->string('ledger_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aver_diff_ledgers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('execution_id')->index();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->json('changed_files');
            $table->json('action_manifests');
            $table->json('patch_verifier_report');
            $table->json('rollback_plan');
            $table->json('evidence_refs')->nullable();
            $table->string('diff_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aver_test_ledgers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('execution_id')->index();
            $table->uuid('command_ledger_id')->nullable()->index();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('test_command_hash', 64)->index();
            $table->integer('exit_code')->nullable();
            $table->json('test_impact');
            $table->json('evidence_refs')->nullable();
            $table->string('test_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aver_repair_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('execution_id')->index();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->integer('attempt')->default(1);
            $table->json('failure_packet');
            $table->json('repair_plan');
            $table->json('evidence_refs')->nullable();
            $table->string('repair_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aver_certified_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('execution_id')->index();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('certification_level', 40)->index();
            $table->json('command_summary');
            $table->json('diff_summary');
            $table->json('test_summary');
            $table->json('repair_summary');
            $table->json('evidence_bundle');
            $table->json('claim_policy');
            $table->json('evidence_refs');
            $table->string('certification_hash', 64)->index();
            $table->timestamps();
        });
    }

    protected function dropAverTables(): void
    {
        Schema::dropIfExists('atlas_aver_certified_executions');
        Schema::dropIfExists('atlas_aver_repair_cycles');
        Schema::dropIfExists('atlas_aver_test_ledgers');
        Schema::dropIfExists('atlas_aver_diff_ledgers');
        Schema::dropIfExists('atlas_aver_command_ledgers');
        Schema::dropIfExists('atlas_aver_executions');
    }
}
