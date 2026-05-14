<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_decision_receipts')) {
            return;
        }

        Schema::create('atlas_decision_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_id', 64)->unique();
            $table->uuid('operation_id')->index();
            $table->uuid('spec_id')->nullable()->index();
            $table->uuid('plan_id')->nullable()->index();
            // L0_manual|L1_assisted|L2_auto_patch|L3_auto_pr|L4_restricted_merge|L5_proposal_only
            $table->string('autonomy_level', 32)->index();
            $table->json('allowed_actions_json')->default('[]');
            $table->json('forbidden_actions_json')->default('[]');
            $table->json('allowed_files_json')->default('[]');
            $table->json('forbidden_files_json')->default('[]');
            $table->json('required_gates_json')->default('[]');
            $table->json('task_ids_json')->default('[]');
            $table->json('context_pack_refs_json')->default('[]');
            $table->string('input_hash', 64);
            $table->string('output_hash', 64);
            $table->string('signature', 128)->nullable();
            $table->timestamp('signed_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['operation_id', 'signed_at'], 'idx_atlas_dr_op_signed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_decision_receipts');
    }
};
