<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_engineering_file_review_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id')->index();
            $table->uuid('patch_artifact_id')->index();
            $table->string('file_path', 1024);
            $table->string('patch_diff_hash', 64)->nullable();
            $table->string('action', 16)->index();
            $table->string('actor', 120)->default('operator');
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['patch_artifact_id', 'file_path'], 'atlas_eng_file_review_patch_file_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_engineering_file_review_decisions');
    }
};
