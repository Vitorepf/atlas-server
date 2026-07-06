<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAtlasEngineeringRunsTable
{
    protected function createAtlasEngineeringRunsTable(): void
    {
        if (! Schema::hasTable('atlas_engineering_runs')) {
            Schema::create('atlas_engineering_runs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('project_id')->nullable();
                $t->string('status')->nullable();
                $t->string('decision')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
            });
        }
    }
}
