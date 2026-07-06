<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAiTracesTable
{
    protected function createAiTracesTable(): void
    {
        if (! Schema::hasTable('ai_traces')) {
            Schema::create('ai_traces', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id')->nullable();
                $t->string('source_type')->default('app');
                $t->uuid('source_id')->nullable();
                $t->string('status')->default('completed');
                $t->text('operator_input')->nullable();
                $t->text('response_text')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }
    }
}
