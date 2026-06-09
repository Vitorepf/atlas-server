<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staged operator-skill proposals: a skill Atlas auto-BUILT (generated SKILL.md) from a
 * recurring pattern, sitting in the sandbox staging dir — NEVER in the live vault until
 * the operator promotes it with an explicit --confirm. This table is the review queue +
 * the audit trail; the live vault is written ONLY by the promoter command.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operator_skill_proposals')) {
            return;
        }

        Schema::create('operator_skill_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operator_id', 120)->index();
            $table->string('pattern_id', 80);
            $table->string('detection_id')->nullable();
            $table->string('slug', 160);
            $table->string('title');
            $table->text('description');
            $table->string('status', 24)->default('staged'); // staged | promoted | rejected
            $table->text('staging_path');
            $table->text('promoted_path')->nullable();
            $table->float('confidence')->default(0.0);
            $table->string('privacy_class', 20)->default('normal');
            $table->json('evidence')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_id', 'pattern_id']);
            $table->index(['operator_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_skill_proposals');
    }
};
