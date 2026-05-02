<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('router_decision_id')->nullable()->index();
            $table->string('policy_version', 32)->default('atlas-decide-v1');
            $table->string('decision_mode', 32)->default('atlas_decide');
            $table->string('route_mode', 64)->nullable();
            $table->string('task_type', 80)->nullable();
            $table->string('risk_level', 40)->nullable();
            $table->string('selected_provider', 32);
            $table->string('selected_model', 120)->nullable();
            $table->string('fallback_provider', 32)->nullable();
            $table->string('operator_requested_provider', 32)->default('auto');
            $table->string('requested_provider', 32)->nullable();
            $table->boolean('was_overridden')->default(false);
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->json('signals');
            $table->json('candidates')->nullable();
            $table->json('constraints')->nullable();
            $table->json('metrics_snapshot')->nullable();
            $table->text('reason');
            $table->timestamps();

            $table->index(['decision_mode', 'created_at']);
            $table->index(['selected_provider', 'created_at']);
            $table->index(['task_type', 'created_at']);
            $table->index(['was_overridden', 'created_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_decisions_updated_at
                BEFORE UPDATE ON ai_decisions
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_decisions');
    }
};
