<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_engineering_code_file_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('file_path', 500)->unique();
            $table->string('module_slug', 160)->index();
            $table->string('language', 40)->nullable()->index();
            $table->string('source_hash', 64)->index();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->json('symbols_json')->default('[]');
            $table->json('relations_json')->default('{}');
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'archived_at', 'source_hash'], 'idx_atlas_eng_file_snapshots_active_hash');
            $table->index(['status', 'archived_at', 'module_slug'], 'idx_atlas_eng_file_snapshots_active_module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_engineering_code_file_snapshots');
    }
};
