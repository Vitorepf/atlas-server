<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesSoftwareTwinTables
{
    protected function createSoftwareTwinTables(): void
    {
        $this->dropSoftwareTwinTables();

        Schema::create('atlas_software_twin_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120);
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

    protected function dropSoftwareTwinTables(): void
    {
        Schema::dropIfExists('atlas_software_twin_snapshots');
    }
}
