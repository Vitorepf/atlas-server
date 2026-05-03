<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_open_brain_access_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('surface', 40)->index();
            $table->string('requester', 120)->nullable()->index();
            $table->string('action', 80)->index();
            $table->string('status', 32)->default('completed')->index();
            $table->string('workspace_hash', 64)->nullable()->index();
            $table->string('workspace_label', 220)->nullable();
            $table->string('context_pack_hash', 64)->nullable()->index();
            $table->unsignedInteger('context_refs_count')->default(0);
            $table->unsignedInteger('memory_refs_count')->default(0);
            $table->boolean('provider_safe')->default(true)->index();
            $table->json('query_json')->default('{}');
            $table->json('result_summary_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamp('accessed_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_open_brain_access_logs');
    }
};
