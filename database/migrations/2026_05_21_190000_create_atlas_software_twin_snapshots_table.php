<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_software_twin_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.software_twin.snapshot.v1');
            $table->string('status', 40)->index();
            $table->string('target')->nullable()->index();
            $table->string('target_path')->nullable()->index();
            $table->json('software_twin');
            $table->json('quality_score');
            $table->json('blockers')->nullable();
            $table->json('claim_policy')->nullable();
            $table->string('snapshot_hash', 64)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_software_twin_snapshots');
    }
};
