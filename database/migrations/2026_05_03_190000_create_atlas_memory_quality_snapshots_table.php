<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_memory_quality_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace')->nullable()->index();
            $table->string('workspace_hash', 64)->nullable()->index();
            $table->string('source_type', 40)->default('manual')->index();
            $table->string('source_id', 120)->nullable()->index();
            $table->string('status', 32)->index();
            $table->unsignedSmallInteger('score')->default(0)->index();
            $table->json('components_json')->default('{}');
            $table->json('counts_json')->default('{}');
            $table->json('ratios_json')->default('{}');
            $table->json('issues_json')->default('[]');
            $table->json('recommendations_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('snapshot_at')->useCurrent()->index();
            $table->timestamps();

            $table->index(['workspace_hash', 'snapshot_at'], 'idx_atlas_memory_quality_workspace_time');
            $table->index(['status', 'snapshot_at'], 'idx_atlas_memory_quality_status_time');
            $table->index(['score', 'snapshot_at'], 'idx_atlas_memory_quality_score_time');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_atlas_memory_quality_snapshots_updated_at
                BEFORE UPDATE ON atlas_memory_quality_snapshots
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_memory_quality_snapshots');
    }
};
