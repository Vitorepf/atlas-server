<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 120);
            $table->string('subject_type', 120)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('actor_type', 80)->default('system');
            $table->string('actor_id', 160)->nullable();
            $table->string('severity', 20)->default('info');
            $table->text('summary');
            $table->json('evidence')->default('{}');
            $table->json('privacy')->default('{}');
            $table->json('refs')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['event_type', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['severity', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
