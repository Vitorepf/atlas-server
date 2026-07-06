<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAiMessagesTable
{
    protected function createAiMessagesTable(): void
    {
        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id');
                $t->uuid('trace_id')->nullable();
                $t->integer('position')->default(1);
                $t->string('role');
                $t->string('status')->default('completed');
                $t->text('content')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
            });
        }
    }
}
