<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tool_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();
            $table->uuid('thread_id')->nullable()->index();
            $table->string('tool', 64);
            $table->string('risk', 16)->default('low');
            $table->string('permission_status', 16)->default('auto');
            $table->string('approval_source', 32)->nullable();
            $table->json('input_summary');
            $table->json('output_summary');
            $table->json('changed_files')->nullable();
            $table->string('checkpoint_id', 128)->nullable();
            $table->integer('exit_code')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['trace_id', 'created_at']);
            $table->index(['tool', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_events');
    }
};
