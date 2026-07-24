<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_engineering_code_file_snapshots')) {
            return;
        }

        if (! Schema::hasColumn('atlas_engineering_code_file_snapshots', 'workspace_id')) {
            Schema::table('atlas_engineering_code_file_snapshots', static function (Blueprint $table): void {
                $table->string('workspace_id', 160)->default('atlas-server')->index();
            });
        }

        Schema::table('atlas_engineering_code_file_snapshots', static function (Blueprint $table): void {
            $table->dropUnique('atlas_engineering_code_file_snapshots_file_path_unique');
        });

        Schema::table('atlas_engineering_code_file_snapshots', static function (Blueprint $table): void {
            $table->unique(['workspace_id', 'file_path'], 'uniq_atlas_eng_file_snap_ws_path');
        });
    }
};
