<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAtlasEngineeringEvidenceTable
{
    protected function createAtlasEngineeringEvidenceTable(): void
    {
        if (! Schema::hasTable('atlas_engineering_evidence')) {
            Schema::create('atlas_engineering_evidence', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('project_id')->nullable();
                $t->uuid('task_id')->nullable();
                $t->string('evidence_type')->nullable();
                $t->string('target_id')->nullable();
                $t->string('status')->nullable();
                $t->float('confidence')->nullable();
                $t->text('summary')->nullable();
                $t->text('command')->nullable();
                $t->text('output_excerpt')->nullable();
                $t->json('files')->nullable();
                $t->json('metadata')->nullable();
                $t->string('source')->nullable();
                $t->timestamp('recorded_at')->nullable();
                $t->timestamps();
            });
        }
    }
}
