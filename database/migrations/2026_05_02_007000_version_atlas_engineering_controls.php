<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_engineering_controls', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_engineering_controls', 'definition_hash')) {
                $table->string('definition_hash', 64)->nullable()->after('metadata')->index();
            }
            if (! Schema::hasColumn('atlas_engineering_controls', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('definition_hash')->index();
            }
            if (! Schema::hasColumn('atlas_engineering_controls', 'versioned_at')) {
                $table->timestamp('versioned_at')->nullable()->after('version');
            }
        });

        Schema::create('atlas_engineering_control_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('control_id')->nullable()->index();
            $table->string('slug', 120)->index();
            $table->unsignedInteger('version');
            $table->string('definition_hash', 64);
            $table->json('definition_json')->default('{}');
            $table->string('changed_by', 120)->default('atlas_engineering_control_registry');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->unique(['slug', 'definition_hash'], 'idx_atlas_eng_control_rev_slug_hash');
            $table->index(['slug', 'version'], 'idx_atlas_eng_control_rev_slug_version');
        });

        Schema::table('atlas_engineering_control_results', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_engineering_control_results', 'control_definition_hash')) {
                $table->string('control_definition_hash', 64)->nullable()->after('control_slug')->index();
            }
            if (! Schema::hasColumn('atlas_engineering_control_results', 'control_version')) {
                $table->unsignedInteger('control_version')->nullable()->after('control_definition_hash')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('atlas_engineering_control_results', function (Blueprint $table): void {
            foreach (['control_version', 'control_definition_hash'] as $column) {
                if (Schema::hasColumn('atlas_engineering_control_results', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('atlas_engineering_control_revisions');

        Schema::table('atlas_engineering_controls', function (Blueprint $table): void {
            foreach (['versioned_at', 'version', 'definition_hash'] as $column) {
                if (Schema::hasColumn('atlas_engineering_controls', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
