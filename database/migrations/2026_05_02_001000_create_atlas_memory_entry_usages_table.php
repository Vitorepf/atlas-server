<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_memory_entry_usages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('memory_entry_id')->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('context_snapshot_id')->nullable()->index();
            $table->uuid('thread_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();
            $table->string('memory_type', 40)->index();
            $table->string('scope_type', 40)->index();
            $table->string('scope_id', 120)->nullable()->index();
            $table->string('source_type', 80)->default('context_pack')->index();
            $table->string('source_id', 120)->nullable()->index();
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('included_reason')->nullable();
            $table->json('source_ref_json')->default('{}');
            $table->json('context_payload_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->string('feedback_action', 32)->nullable()->index();
            $table->unsignedTinyInteger('feedback_score')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('feedback_recorded_at')->nullable()->index();
            $table->timestamp('used_at')->useCurrent()->index();
            $table->timestamps();

            $table->index(['trace_id', 'memory_entry_id'], 'idx_atlas_memory_usages_trace_memory');
            $table->index(['memory_entry_id', 'used_at'], 'idx_atlas_memory_usages_memory_used');
            $table->index(['feedback_action', 'used_at'], 'idx_atlas_memory_usages_feedback_used');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_atlas_memory_entry_usages_updated_at
                BEFORE UPDATE ON atlas_memory_entry_usages
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_memory_entry_usages');
    }
};
