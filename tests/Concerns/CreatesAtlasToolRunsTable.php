<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAtlasToolRunsTable
{
    protected function createAtlasToolRunsTable(): void
    {
        if (! Schema::hasTable('atlas_tool_runs')) {
            Schema::create('atlas_tool_runs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('tool_slug')->nullable();
                $t->text('workspace')->nullable();
                $t->string('run_context_type')->nullable();
                $t->string('run_context_id')->nullable();
                $t->string('status')->nullable();
                $t->json('summary_json')->nullable();
                $t->timestamps();
            });
        }
    }
}
