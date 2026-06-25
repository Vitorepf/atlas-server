<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_loop_goodhart_receipts')) {
            return;
        }

        Schema::create('atlas_loop_goodhart_receipts', function (Blueprint $table): void {
            $table->uuid('receipt_id')->primary();
            $table->string('campaign_id', 64)->nullable()->index();
            $table->string('task_id', 96)->nullable()->index();
            $table->timestamp('verdict_at')->index();
            $table->boolean('refused');
            $table->json('pattern_ids');
            $table->json('facts');
            $table->json('source_classes');
            $table->json('evidence_refs');
            $table->string('judge_commit_sha', 64);
            $table->timestamps();

            $table->index(['refused', 'verdict_at'], 'idx_goodhart_refused_verdict_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_loop_goodhart_receipts');
    }
};
