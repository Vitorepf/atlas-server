<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_strategy_rivals_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title', 180);
            $table->string('decision_domain', 120)->default('strategic_decision')->index();
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('decision_made_at')->nullable()->index();
            $table->text('baseline_choice')->nullable();
            $table->text('atlas_assisted_choice')->nullable();
            $table->text('context_summary')->nullable();
            $table->unsignedSmallInteger('horizon_days')->default(90)->index();
            $table->string('source_hash', 64)->nullable()->unique();
            $table->json('tags_json')->default('[]');
            $table->json('metrics_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_strategy_rivals_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->index();
            $table->unsignedSmallInteger('horizon_days')->index();
            $table->timestamp('review_due_at')->index();
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedTinyInteger('regret_score')->nullable();
            $table->unsignedTinyInteger('alignment_score')->nullable();
            $table->unsignedTinyInteger('agency_score')->nullable();
            $table->text('outcome_summary')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->unique(['case_id', 'horizon_days'], 'idx_atlas_strategy_rivals_case_horizon');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['atlas_strategy_rivals_cases', 'atlas_strategy_rivals_reviews'] as $table) {
                DB::statement(<<<SQL
                    CREATE TRIGGER trg_{$table}_updated_at
                    BEFORE UPDATE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
                SQL);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_strategy_rivals_reviews');
        Schema::dropIfExists('atlas_strategy_rivals_cases');
    }
};
