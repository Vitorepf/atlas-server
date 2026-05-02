<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_verbatim_memories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('memory_entry_id')->nullable()->index();
            $table->string('verbatim_type', 40)->index();
            $table->string('scope_type', 40)->default('global')->index();
            $table->string('scope_id', 120)->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('task_id')->nullable()->index();
            $table->uuid('engineering_run_id')->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();
            $table->string('user_id', 120)->nullable()->index();
            $table->string('title', 180)->nullable();
            $table->longText('verbatim_text');
            $table->longText('redacted_text');
            $table->text('summary')->nullable();
            $table->string('privacy_class', 24)->default('normal')->index();
            $table->boolean('external_ai_allowed')->default(true)->index();
            $table->string('redaction_status', 24)->default('clean')->index();
            $table->string('content_hash', 64)->index();
            $table->string('redacted_hash', 64)->nullable()->index();
            $table->string('source_type', 80)->default('manual')->index();
            $table->string('source_id', 120)->nullable()->index();
            $table->string('source_label', 180)->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->json('tags')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('recorded_at')->useCurrent()->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletesTz();

            $table->index(['scope_type', 'scope_id', 'status'], 'idx_atlas_verbatim_scope_status');
            $table->index(['verbatim_type', 'status', 'privacy_class'], 'idx_atlas_verbatim_type_status_privacy');
            $table->index(['project_id', 'verbatim_type', 'status'], 'idx_atlas_verbatim_project_type');
            $table->index(['task_id', 'verbatim_type', 'status'], 'idx_atlas_verbatim_task_type');
            $table->index(['engineering_run_id', 'verbatim_type', 'status'], 'idx_atlas_verbatim_run_type');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_atlas_verbatim_memories_updated_at
                BEFORE UPDATE ON atlas_verbatim_memories
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_verbatim_memories');
    }
};
