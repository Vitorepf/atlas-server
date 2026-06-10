<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S3.F3 — the per-cycle history of the RECURSIVE GOVERNED SELF-IMPROVEMENT LOOP.
 *
 * One durable row per loop cycle, written AFTER the cycle runs. This is the source
 * of truth for the HONEST meta-metric (`atlas:self-construct --status`): the live
 * per-cycle counts {signals_detected, generated, relevance_passed, relevance_rejected,
 * branches_delivered, brain_nodes_added} + the relevance-pass-rate trend + brain
 * growth. NOTHING is self-declared and NOTHING is hardcoded — every number is the
 * loop's own measured outcome (the relevance gate's verdict, the brain's node delta),
 * persisted verbatim so the trend is computed from real history, not a claim.
 *
 * ANTI-GOODHART: there is no "success" column the loop sets. `relevance_passed` is the
 * count the OUT-OF-PROCESS gate certified; `relevance_rejected` is the count it
 * refused. The pass-RATE is derived (passed / delivered) at read time, never stored as
 * a flattering scalar the loop could inflate.
 *
 * Plain portable SQL — runs identically on pgsql (dev) and sqlite (tests), the same
 * boundary the AURG tables hold. Append-mostly; a row is upserted on the deterministic
 * `cycle_hash` so re-running an identical cycle does not double-count (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_self_construct_cycles')) {
            Schema::create('atlas_self_construct_cycles', function (Blueprint $table): void {
                $table->bigIncrements('id');
                // Deterministic fingerprint of the cycle's HONEST counts + branches +
                // receipt — re-running an identical cycle upserts the same row.
                $table->string('cycle_hash', 64)->unique();
                // The loop's own receipt (from the summary) — links a history row back
                // to the exact cycle result the operator can re-derive.
                $table->string('receipt_hash', 64)->index();
                $table->boolean('brain_anchored')->default(true);

                // ---- the HONEST per-cycle counts (the meta-metric source) ----
                $table->unsignedInteger('signals_detected')->default(0);
                // generated = signals that produced a real delivery (a branch was cut),
                // before the relevance gate's verdict.
                $table->unsignedInteger('generated')->default(0);
                // relevance_passed = of the generated, the count the OUT-OF-PROCESS gate
                // certified on-target (the only "worthy" state).
                $table->unsignedInteger('relevance_passed')->default(0);
                // relevance_rejected = of the generated, the count the gate refused
                // (off-target / off-concern — the 412-line-garbage failure, caught).
                $table->unsignedInteger('relevance_rejected')->default(0);
                // branches_delivered = accepted branches kept for the operator to merge
                // (== relevance_passed; stored explicitly for the operator-facing view).
                $table->unsignedInteger('branches_delivered')->default(0);
                // brain_nodes_added = AURG node-count delta across the cycle (the
                // recursion substrate — what the NEXT cycle's brain query can reach).
                $table->integer('brain_nodes_added')->default(0);

                // Snapshot of the absolute brain size after the cycle (for the growth
                // trend without re-deriving from deltas).
                $table->unsignedInteger('brain_nodes_total')->default(0);

                $table->timestamps();

                $table->index('created_at', 'idx_atlas_self_construct_cycles_created');
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                DROP TRIGGER IF EXISTS trg_atlas_self_construct_cycles_updated_at ON atlas_self_construct_cycles;
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_atlas_self_construct_cycles_updated_at
                BEFORE UPDATE ON atlas_self_construct_cycles
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_self_construct_cycles');
    }
};
