<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the Atlas Evolution Loop 24h runtime schema to the design blueprint:
 *
 *  - `atlas_loop_targets`: the discovery candidate ledger (the "Sources" stage at
 *    repo scale) — what {@see AtlasLoopTargetDiscoveryService} ranks and the
 *    supervisor pulls from to self-feed the queue.
 *  - campaign budget/cost/lifecycle columns (base_workspace, cost cap + spend,
 *    elapsed_seconds so paused time never burns budget and a crash resumes against
 *    REMAINING budget, paused_at/completed_at).
 *  - task admissibility columns (self_contained as a first-class claim predicate;
 *    acceptance_hash; worker heartbeat).
 *  - the never-merge invariant's DB layer (pgsql CHECK + plpgsql trigger) on top of
 *    the proven Eloquent guard — three layers so propose-only survives any caller.
 *
 * pgsql-only DDL is driver-guarded; sqlite (test driver) gets the columns/tables and
 * relies on the Eloquent guard for never-merge (which is already proven on sqlite).
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::table('atlas_loop_campaigns', function (Blueprint $table): void {
            $table->string('base_workspace', 1024)->nullable()->after('goal');     // repo root discovery scans / generator reads
            $table->unsignedInteger('max_usd_cents')->default(0)->after('max_tasks'); // 0 = no cost cap
            $table->unsignedInteger('spend_usd_cents')->default(0)->after('loopbacks');
            $table->unsignedInteger('elapsed_seconds')->default(0)->after('spend_usd_cents'); // persisted, not recomputed
            $table->boolean('kill_switch')->default(false)->after('stop_reason');
            $table->timestamp('paused_at')->nullable()->after('started_at');
            $table->timestamp('completed_at')->nullable()->after('finished_at');
        });

        Schema::table('atlas_loop_tasks', function (Blueprint $table): void {
            // Self-contained is a FIRST-CLASS claim predicate: the cp -R + plain-`php`
            // explorer can only ever lease a target it can honestly run. Never faked.
            $table->boolean('self_contained')->default(true)->index()->after('source');
            $table->string('acceptance_hash', 64)->nullable()->after('dedupe_key');
            $table->timestamp('heartbeat_at')->nullable()->after('lease_expires_at'); // worker mid-grind liveness
        });

        // The discovery candidate ledger — deterministic, provider-free "Sources".
        Schema::create('atlas_loop_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->string('schema_version', 120)->default('atlas.loop.target.v1');
            $table->string('target_path', 1024);
            $table->string('target_key', 64)->index();        // sha256(campaign|path)
            $table->string('content_hash', 64);               // re-score only when file content changes
            $table->string('status', 24)->default('candidate')->index(); // candidate|claimed|queued|proposed|quarantined|exhausted
            $table->float('score')->default(0)->index();
            $table->float('self_contained_score')->default(0);
            $table->float('improvement_score')->default(0);
            $table->float('novelty_score')->default(0);
            $table->json('signals')->nullable();
            $table->json('lineage')->nullable();              // origin: discovery|neighbor|sibling|failure (+ parent)
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->string('claimed_by', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->string('reason', 160)->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'target_key']);    // idempotent upsert on (campaign, path)
        });

        if ($isPgsql) {
            // The never-merge invariant, DB layer (defense in depth over the Eloquent guard).
            DB::statement('ALTER TABLE atlas_loop_proposals ADD CONSTRAINT chk_atlas_loop_proposals_never_merged CHECK (merged_to_main = false)');
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION atlas_loop_block_merge() RETURNS trigger AS $$
                BEGIN
                    IF NEW.merged_to_main IS TRUE THEN
                        RAISE EXCEPTION 'atlas_loop_proposals.merged_to_main must be false (propose-only invariant)';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::statement('CREATE TRIGGER atlas_loop_block_merge_trg BEFORE INSERT OR UPDATE ON atlas_loop_proposals FOR EACH ROW EXECUTE FUNCTION atlas_loop_block_merge()');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS atlas_loop_block_merge_trg ON atlas_loop_proposals');
            DB::statement('DROP FUNCTION IF EXISTS atlas_loop_block_merge()');
            DB::statement('ALTER TABLE atlas_loop_proposals DROP CONSTRAINT IF EXISTS chk_atlas_loop_proposals_never_merged');
        }

        Schema::dropIfExists('atlas_loop_targets');

        Schema::table('atlas_loop_tasks', function (Blueprint $table): void {
            $table->dropColumn(['self_contained', 'acceptance_hash', 'heartbeat_at']);
        });

        Schema::table('atlas_loop_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['base_workspace', 'max_usd_cents', 'spend_usd_cents', 'elapsed_seconds', 'kill_switch', 'paused_at', 'completed_at']);
        });
    }
};
