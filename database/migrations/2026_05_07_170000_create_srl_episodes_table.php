<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('srl_episodes')) {
            Schema::create('srl_episodes', function (Blueprint $table): void {
                $table->id();
                $table->string('envelope_id', 80);
                $table->string('target_flow', 80);
                $table->string('domain', 64)->default('learning');
                $table->string('study_session_id', 80)->nullable();
                $table->json('forethought')->nullable();
                $table->json('performance_observations')->nullable();
                $table->json('self_reflection')->nullable();
                $table->string('completion_status', 32)->default('partial_forethought');
                $table->timestamps();
                $table->index(['envelope_id', 'target_flow']);
                $table->index(['domain', 'created_at']);
                $table->index('completion_status');
            });
        }

        if (! Schema::hasTable('srl_preferences')) {
            Schema::create('srl_preferences', function (Blueprint $table): void {
                $table->id();
                $table->string('scope', 32);
                $table->string('domain', 64)->nullable();
                $table->boolean('enabled')->default(false);
                $table->json('phase_config')->nullable();
                $table->timestamps();
                $table->unique(['scope', 'domain']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('srl_preferences');
        Schema::dropIfExists('srl_episodes');
    }
};
