<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAiThreadsTable
{
    protected function createAiThreadsTable(): void
    {
        if (! Schema::hasTable('ai_threads')) {
            Schema::create('ai_threads', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->text('title');
                $table->text('summary')->nullable();
                $table->string('status')->default('active');
                $table->string('surface')->default('app');
                $table->string('workspace')->nullable();
                $table->string('source_type')->nullable();
                $table->uuid('source_id')->nullable();
                $table->uuid('last_trace_id')->nullable();
                $table->string('last_provider')->nullable();
                $table->integer('message_count')->default(0);
                $table->timestamp('last_message_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }
}
