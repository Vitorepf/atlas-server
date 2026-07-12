<?php

declare(strict_types=1);

use App\Services\Ai\Cognition\ImmuneVerdictLedger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(ImmuneVerdictLedger::TABLE)) {
            return;
        }

        Schema::create(ImmuneVerdictLedger::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 80)->default(ImmuneVerdictLedger::SCHEMA_VERSION);
            $table->string('candidate_hash', 64)->index();
            $table->string('writer', 120)->index();
            $table->json('gate_statuses');
            $table->string('promotion_status', 40)->index();
            $table->json('blocking_gate_ids')->default('[]');
            $table->json('pending_gate_ids')->default('[]');
            $table->json('expected_block_gate_ids')->default('[]');
            $table->string('sample_label', 40)->nullable()->index();
            $table->json('metadata')->default('{}');
            $table->timestampTz('decided_at')->index();
            $table->timestampsTz();

            $table->index(['writer', 'decided_at'], 'immune_verdict_ledger_writer_decided_idx');
            $table->index(['sample_label', 'decided_at'], 'immune_verdict_ledger_label_decided_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(ImmuneVerdictLedger::TABLE);
    }
};
