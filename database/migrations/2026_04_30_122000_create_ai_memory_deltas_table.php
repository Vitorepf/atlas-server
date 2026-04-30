<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_memory_deltas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_trace_id')->nullable()->index();
            $table->uuid('source_session_id')->nullable()->index();
            $table->string('source_workspace')->nullable();
            $table->string('type', 32)->default('process');
            $table->text('claim');
            $table->json('evidence');
            $table->string('scope', 255)->default('global');
            $table->float('confidence')->default(0.5);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('use_when')->nullable();
            $table->json('do_not_use_when')->nullable();
            $table->boolean('requires_confirmation')->default(true);
            $table->string('status', 16)->default('pending');
            $table->uuid('superseded_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'type']);
            $table->index(['scope', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
    }
};
