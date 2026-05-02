<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
            $table->text('body');
            $table->text('summary')->nullable();
            $table->unsignedTinyInteger('importance')->default(3);
            $table->unsignedSmallInteger('priority')->default(50);
            $table->decimal('confidence', 5, 3)->nullable();
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

            $table->index(['scope_type', 'scope_id', 'status'], 'idx_atlas_memory_scope_status');
            $table->index(['memory_type', 'status', 'priority'], 'idx_atlas_memory_type_status_priority');
            $table->index(['project_id', 'memory_type', 'status'], 'idx_atlas_memory_project_type');
            $table->index(['task_id', 'memory_type', 'status'], 'idx_atlas_memory_task_type');
            $table->index(['engineering_run_id', 'memory_type', 'status'], 'idx_atlas_memory_run_type');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_atlas_memory_entries_updated_at
                BEFORE UPDATE ON atlas_memory_entries
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_memory_entries');
    }
};
