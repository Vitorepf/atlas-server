<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesOperatorApprovalTable
{
    protected function createOperatorApprovalTable(): void
    {
        $this->dropOperatorApprovalTable();

        Schema::create('ai_operator_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.operator_approval.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->string('job_id', 120)->nullable()->index();
            $table->string('requested_action', 200)->index();
            $table->string('risk_level', 40)->index();
            $table->string('gate_mode', 40)->index();
            $table->boolean('approval_required')->default(true)->index();
            $table->text('reason');
            $table->json('options')->nullable();
            $table->string('status', 40)->default('pending')->index();
            $table->string('operator_decision', 40)->nullable()->index();
            $table->string('operator', 160)->nullable();
            $table->text('operator_note')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('decided_at')->nullable()->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->json('evidence_refs')->nullable();
            $table->string('receipt_hash', 64)->nullable()->index();
            $table->string('hash', 64)->index();
            $table->timestamps();
        });
    }

    protected function dropOperatorApprovalTable(): void
    {
        Schema::dropIfExists('ai_operator_approvals');
    }
}
