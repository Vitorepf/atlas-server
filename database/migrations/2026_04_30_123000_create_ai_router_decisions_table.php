<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_router_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('mode', 32)->default('direct');
            $table->string('selected_provider', 32);
            $table->string('fallback_provider', 32)->nullable();
            $table->json('signals');
            $table->text('reason');
            $table->boolean('was_overridden')->default(false);
            $table->timestamps();

            $table->index(['mode', 'created_at']);
            $table->index(['selected_provider', 'created_at']);
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_ai_router_decisions_updated_at
            BEFORE UPDATE ON ai_router_decisions
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_router_decisions');
    }
};
