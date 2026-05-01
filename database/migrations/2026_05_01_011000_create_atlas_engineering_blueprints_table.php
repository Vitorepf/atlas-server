<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_engineering_blueprints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('project_step_id')->nullable()->index();
            $table->string('status', 32)->default('frozen');
            $table->unsignedInteger('version')->default(1);
            $table->string('source', 120)->default('atlas_engineering_contract');
            $table->json('contract_json')->default('{}');
            $table->json('blueprint_json')->default('{}');
            $table->string('content_hash', 64);
            $table->timestamp('frozen_at')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'content_hash'], 'uq_atlas_engineering_blueprints_task_hash');
            $table->index(['task_id', 'status', 'version'], 'idx_atlas_engineering_blueprints_task_status');
            $table->index(['project_id', 'status', 'version'], 'idx_atlas_engineering_blueprints_project_status');
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_atlas_engineering_blueprints_updated_at
            BEFORE UPDATE ON atlas_engineering_blueprints
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_engineering_blueprints');
    }
};
