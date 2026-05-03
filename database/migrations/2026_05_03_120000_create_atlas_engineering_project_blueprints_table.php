<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_engineering_project_blueprints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->index();
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('source', 120)->default('atlas_project_blueprint');
            $table->string('created_by', 120)->default('atlas_ai');
            $table->json('blueprint_json')->default('{}');
            $table->json('validation_json')->default('{}');
            $table->json('human_exception_json')->default('{}');
            $table->string('content_hash', 64);
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'version'], 'uq_atlas_eng_project_blueprints_project_version');
            $table->unique(['project_id', 'content_hash'], 'uq_atlas_eng_project_blueprints_project_hash');
            $table->index(['project_id', 'status', 'version'], 'idx_atlas_eng_project_blueprints_project_status');
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_atlas_engineering_project_blueprints_updated_at
            BEFORE UPDATE ON atlas_engineering_project_blueprints
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_engineering_project_blueprints');
    }
};
