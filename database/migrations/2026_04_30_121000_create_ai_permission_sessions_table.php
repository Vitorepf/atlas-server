<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_permission_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();
            $table->string('workspace');
            $table->string('mode', 16)->default('write');
            $table->json('allowed_tools')->nullable();
            $table->json('allowed_paths')->nullable();
            $table->json('denied_patterns')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('granted_by', 32)->default('operator_interactive');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['workspace', 'expires_at']);
            $table->index(['mode', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_permission_sessions');
    }
};
