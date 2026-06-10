<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Builds just the Polymarket executor tables on the test connection (sqlite).
 *
 * The full migration set can't run under RefreshDatabase on sqlite (some
 * pre-existing migrations are Postgres-only: CREATE TRIGGER ... EXECUTE
 * FUNCTION). This mirrors the repo's Creates*Tables convention: create exactly
 * what the test needs, drop-first for isolation. Kept in sync with
 * database/migrations/2026_06_10_160000_create_atlas_poly_exec_tables.php.
 */
trait CreatesPolyExecTables
{
    protected function createPolyExecTables(): void
    {
        $this->dropPolyExecTables();

        Schema::create('atlas_poly_exec_baskets', function (Blueprint $table): void {
            $table->id();
            $table->string('basket_id', 40)->unique();
            $table->string('session_id', 40)->index();
            $table->string('mode', 8);
            $table->string('event_slug', 180)->index();
            $table->string('kind', 30);
            $table->string('execution_class', 40);
            $table->string('status', 20)->index();
            $table->unsignedSmallInteger('n_legs');
            $table->unsignedSmallInteger('legs_filled')->default(0);
            $table->decimal('target_sets', 14, 4)->default(0);
            $table->decimal('target_sum', 10, 6)->default(0);
            $table->decimal('target_cost_usd', 12, 4)->default(0);
            $table->decimal('est_profit_usd', 12, 4)->default(0);
            $table->decimal('est_edge_per_set', 10, 6)->default(0);
            $table->decimal('est_fee_usd', 12, 4)->default(0);
            $table->decimal('est_gas_usd', 12, 4)->default(0);
            $table->decimal('cap_usd', 12, 4)->default(0);
            $table->unsignedInteger('slippage_bps')->default(0);
            $table->decimal('realized_cost_usd', 12, 4)->default(0);
            $table->decimal('unwind_proceeds_usd', 12, 4)->default(0);
            $table->decimal('realized_pnl_usd', 12, 4)->default(0);
            $table->string('resolution_at', 40)->nullable();
            $table->text('error')->nullable();
            $table->json('plan')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_poly_exec_legs', function (Blueprint $table): void {
            $table->id();
            $table->string('basket_id', 40)->index();
            $table->unsignedSmallInteger('position');
            $table->string('token', 80);
            $table->string('question', 300)->nullable();
            $table->decimal('plan_depth', 14, 4)->default(0);
            $table->decimal('target_price', 10, 6)->default(0);
            $table->decimal('limit_price', 10, 6)->default(0);
            $table->decimal('target_size', 14, 4)->default(0);
            $table->string('status', 16)->default('pending')->index();
            $table->decimal('filled_size', 14, 4)->default(0);
            $table->decimal('avg_fill_price', 10, 6)->default(0);
            $table->decimal('cost_usd', 12, 4)->default(0);
            $table->string('order_id', 120)->nullable();
            $table->decimal('unwind_size', 14, 4)->default(0);
            $table->decimal('unwind_proceeds_usd', 12, 4)->default(0);
            $table->string('unwind_order_id', 120)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['basket_id', 'position'], 'uq_poly_exec_leg');
        });

        Schema::create('atlas_poly_exec_events', function (Blueprint $table): void {
            $table->id();
            $table->string('basket_id', 40)->index();
            $table->unsignedInteger('seq');
            $table->string('kind', 20);
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['basket_id', 'seq'], 'uq_poly_exec_event');
        });

        Schema::create('atlas_poly_exec_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('trade_date');
            $table->string('mode', 8);
            $table->decimal('deployed_usd', 14, 4)->default(0);
            $table->decimal('realized_pnl_usd', 14, 4)->default(0);
            $table->unsignedInteger('baskets_attempted')->default(0);
            $table->unsignedInteger('baskets_filled')->default(0);
            $table->unsignedInteger('baskets_aborted')->default(0);
            $table->boolean('halted')->default(false);
            $table->timestamps();
            $table->unique(['trade_date', 'mode'], 'uq_poly_exec_daily');
        });
    }

    protected function dropPolyExecTables(): void
    {
        foreach (['atlas_poly_exec_events', 'atlas_poly_exec_legs', 'atlas_poly_exec_daily', 'atlas_poly_exec_baskets'] as $t) {
            Schema::dropIfExists($t);
        }
    }
}
