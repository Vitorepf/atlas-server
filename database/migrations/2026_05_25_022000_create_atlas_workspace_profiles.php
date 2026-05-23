<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_workspace_profiles')) {
            return;
        }

        Schema::create('atlas_workspace_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 200);
            $table->string('kind', 80)->default('product');
            $table->string('workspace_path', 1000)->nullable();
            $table->string('repo_root', 1000)->nullable();
            $table->string('production_status', 80)->default('development')->index();
            $table->text('stack_summary')->nullable();
            $table->json('commands')->nullable();
            $table->json('test_commands')->nullable();
            $table->json('build_commands')->nullable();
            $table->string('dev_server_command', 1000)->nullable();
            $table->json('critical_areas')->nullable();
            $table->string('docs_status', 80)->default('unknown')->index();
            $table->string('default_risk', 40)->default('medium')->index();
            $table->text('deployment_notes')->nullable();
            $table->json('surfaces_enabled')->nullable();
            $table->string('source', 80)->default('operator')->index();
            $table->string('status', 40)->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_workspace_profiles');
    }
};
