<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable state for the Polymarket LIVE executor v1 (long-side only).
 *
 * The executor is idempotent and resumable: the whole state machine is driven
 * off these rows, so a crash after some legs filled resumes exactly where it
 * stopped instead of double-buying. `mode` is sim|live on every row — sim runs
 * the identical machine against real books WITHOUT signing anything, live is
 * gated behind a flag + explicit --confirm. No keys are ever stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_poly_exec_baskets')) {
            Schema::create('atlas_poly_exec_baskets', function (Blueprint $table): void {
                $table->id();
                // Deterministic idempotency key: re-running the same basket_id never
                // re-fills already-filled legs — it resumes from the persisted status.
                $table->string('basket_id', 40)->unique();
                $table->string('session_id', 40)->index();
                $table->string('mode', 8); // sim | live
                $table->string('event_slug', 180)->index();
                $table->string('kind', 30);            // long_sum_under
                $table->string('execution_class', 40); // simple_buy_all_legs
                // planning|gated|verifying|filling|filled|aborting|unwound|halted|failed
                $table->string('status', 20)->index();
                $table->unsignedSmallInteger('n_legs');
                $table->unsignedSmallInteger('legs_filled')->default(0);

                // Plan numbers (what we intend), USD.
                $table->decimal('target_sets', 14, 4)->default(0);
                $table->decimal('target_sum', 10, 6)->default(0);     // sum of best asks per set
                $table->decimal('target_cost_usd', 12, 4)->default(0);
                $table->decimal('est_profit_usd', 12, 4)->default(0); // locked at resolution
                $table->decimal('est_edge_per_set', 10, 6)->default(0);
                $table->decimal('est_fee_usd', 12, 4)->default(0);
                $table->decimal('est_gas_usd', 12, 4)->default(0);
                $table->decimal('cap_usd', 12, 4)->default(0);   // per-basket cap applied
                $table->unsignedInteger('slippage_bps')->default(0);

                // Realized numbers (what actually happened in this run), USD.
                $table->decimal('realized_cost_usd', 12, 4)->default(0);
                $table->decimal('unwind_proceeds_usd', 12, 4)->default(0);
                // Net cash effect of THIS run. For a clean fill the position carries to
                // resolution (est_profit_usd is the locked-at-resolution figure); for an
                // abort this is the unwind slippage cost (negative).
                $table->decimal('realized_pnl_usd', 12, 4)->default(0);

                $table->string('resolution_at', 40)->nullable(); // event endDate, ISO
                $table->text('error')->nullable();
                $table->json('plan')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();

                $table->index(['mode', 'status']);
            });
        }

        if (! Schema::hasTable('atlas_poly_exec_legs')) {
            Schema::create('atlas_poly_exec_legs', function (Blueprint $table): void {
                $table->id();
                $table->string('basket_id', 40)->index();
                $table->unsignedSmallInteger('position'); // thinnest-first fill order, 0-based
                $table->string('token', 80);
                $table->string('question', 300)->nullable();

                $table->decimal('plan_depth', 14, 4)->default(0);  // executable depth at plan time
                $table->decimal('target_price', 10, 6)->default(0); // best ask seen
                $table->decimal('limit_price', 10, 6)->default(0);  // target_price + slippage
                $table->decimal('target_size', 14, 4)->default(0);  // shares to buy = sets

                // pending|filled|partial|failed|unwinding|unwound
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
        }

        if (! Schema::hasTable('atlas_poly_exec_events')) {
            // Append-only audit trail of every state-machine transition.
            Schema::create('atlas_poly_exec_events', function (Blueprint $table): void {
                $table->id();
                $table->string('basket_id', 40)->index();
                $table->unsignedInteger('seq');
                // state_change|gate_block|fill|leg_fail|abort|unwind|halt|kill|reconcile
                $table->string('kind', 20);
                $table->json('detail')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique(['basket_id', 'seq'], 'uq_poly_exec_event');
            });
        }

        if (! Schema::hasTable('atlas_poly_exec_daily')) {
            // Per-day budget ledger: deployed capital and the halt latch.
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
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_poly_exec_events');
        Schema::dropIfExists('atlas_poly_exec_legs');
        Schema::dropIfExists('atlas_poly_exec_daily');
        Schema::dropIfExists('atlas_poly_exec_baskets');
    }
};
