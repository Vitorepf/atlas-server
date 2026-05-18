<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAtlasMemoryEntryTable
{
    protected function createAtlasMemoryEntryTable(): void
    {
        $this->dropAtlasMemoryEntryTable();

        Schema::create('atlas_memory_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('memory_type', 40)->index();
            $table->string('scope_type', 40)->default('global')->index();
            $table->string('scope_id', 120)->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('task_id')->nullable()->index();
            $table->uuid('engineering_run_id')->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();
            $table->string('user_id', 120)->nullable()->index();
            $table->string('title', 180)->nullable();
            $table->string('redacted_title', 180)->nullable();
            $table->text('body');
            $table->text('redacted_body')->nullable();
            $table->text('summary')->nullable();
            $table->text('redacted_summary')->nullable();
            $table->unsignedTinyInteger('importance')->default(3);
            $table->unsignedSmallInteger('priority')->default(50);
            $table->decimal('confidence', 5, 3)->nullable();
            $table->string('privacy_class', 24)->default('normal')->index();
            $table->boolean('external_ai_allowed')->default(true)->index();
            $table->string('redaction_status', 24)->default('clean')->index();
            $table->string('source_type', 80)->default('manual')->index();
            $table->string('source_id', 120)->nullable()->index();
            $table->string('source_label', 180)->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->json('tags')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('recorded_at')->useCurrent()->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->uuid('superseded_by_id')->nullable();
            $table->timestamp('governance_checked_at')->nullable();
            $table->timestamp('privacy_reviewed_at')->nullable()->index();
            // TEOS-I1 / M2 — Temporal Truth Fields (all nullable; back-compat).
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('stale_after')->nullable()->index();
            $table->string('source_hash', 64)->nullable();
            $table->string('authority_level', 40)->nullable()->index();
            $table->timestamps();
            $table->softDeletesTz();
        });
    }

    protected function dropAtlasMemoryEntryTable(): void
    {
        Schema::dropIfExists('atlas_memory_entries');
    }
}
