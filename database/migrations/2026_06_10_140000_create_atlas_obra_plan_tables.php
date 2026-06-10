<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AOBG N3.F1 — the DECOMPOSITION spine (intent → plan-DAG → steps).
 *
 * N3 is THE INVERSION: until now the engine consulted the brain (N1) or the brain
 * watched the engine (N2). N3 makes the brain DRIVE — the operator declares an
 * INTENT in natural language and Atlas decomposes it into an OBRA (a multi-step
 * work) it can execute governed, returning ONE ready-to-merge branch. This RAISES
 * THE UNIT OF WORK from edit → obra.
 *
 * These two tables are the persisted spine the {@see \App\Services\Ai\Obra\AtlasObraPlanService}
 * writes and the (future) obra conductor reads to execute the nodes in dependency
 * order onto ONE accumulating branch:
 *
 *   atlas_obra_plans — one row per decomposed intent (the OBRA header):
 *     {id, intent (REDACTED summary — never raw secrets), workspace_id, status, meta, ts}.
 *   atlas_obra_nodes — one row per DAG node (a natural-language STEP of the obra):
 *     {id, plan_id, seq, title, request, target_area, depends_on (json list of node
 *      ids), status, brain_refs (json — the AURG/context refs cited for the step),
 *      result (json — branch step ref / files / evidence), ts}.
 *
 * Design contract (mirrors the AURG + blackboard migrations' portability + privacy):
 *  - Plain portable SQL: runs identically on pgsql (dev, 127.0.0.1:5433/atlas) and
 *    sqlite (tests). No pgvector, no driver-specific column types. The only
 *    pgsql-specific block is the updated_at trigger, guarded by a driver check (the
 *    canonical pattern in this repo).
 *  - PRIVACY: `intent` is a REDACTED, provider-safe summary of the operator's ask —
 *    never raw secrets; the decomposer redacts before persisting. `request` /
 *    `title` / `target_area` are step labels. `brain_refs` / `result` are small
 *    bounded json carrying ids/hashes/labels/paths only — never source, never raw
 *    memory bodies.
 *  - DAG INTEGRITY: `depends_on` is a json array of node ids in the SAME plan; the
 *    service validates (no cycles, deps resolve, a topological order exists) BEFORE
 *    persisting — the table stores a proven-acyclic plan.
 *  - status columns are first-class (not buried in meta) so a reader can filter
 *    WITHOUT decoding json. plan.status ∈ {planned, running, done, failed, halted};
 *    node.status ∈ {pending, running, done, failed, skipped}.
 *  - Indexes on the read paths: plan(status); node(plan_id), node(plan_id, status),
 *    node(plan_id, seq) — the topological-order read path.
 *
 * SAFETY: these tables are PLAN metadata only. They never touch git, never merge,
 * never push. Writes are local DB only, ZERO provider spend (the plan command is
 * cheap; only the operator's single obra-run command spends, and even then only the
 * per-node delivery does — branch-only, never main).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_obra_plans')) {
            Schema::create('atlas_obra_plans', function (Blueprint $table): void {
                // Deterministic obra id ("obra-<sha1(intent|workspace)[:10]>") so a
                // re-decompose of the same intent in the same workspace is idempotent.
                $table->string('id', 200)->primary();
                // REDACTED, provider-safe summary of the operator's intent (a label,
                // never raw secrets). Bounded by the service before persisting.
                $table->string('intent', 1000);
                $table->string('workspace_id', 160)->index();
                // planned | running | done | failed | halted.
                $table->string('status', 16)->default('planned')->index();
                // Small bounded json (node_count, decomposer, provider, honesty, ...).
                $table->json('meta')->default('{}');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_obra_nodes')) {
            Schema::create('atlas_obra_nodes', function (Blueprint $table): void {
                // Deterministic node id ("<plan_id>:n<seq>") — stable, dependency-safe.
                $table->string('id', 240)->primary();
                $table->string('plan_id', 200)->index();
                // Topological sequence (0-based): the dependency-respecting exec order.
                $table->integer('seq');
                // A short human label for the step.
                $table->string('title', 300);
                // The natural-language step the per-node executor (AtlasMissionService)
                // delivers — a request, not source.
                $table->text('request');
                // A file/dir/module hint that biases the step's brain + code recall.
                $table->string('target_area', 500)->nullable();
                // json array of node ids (in THIS plan) this node depends on. The
                // service proves the whole set is acyclic before persisting.
                $table->json('depends_on')->default('[]');
                // pending | running | done | failed | skipped.
                $table->string('status', 16)->default('pending')->index();
                // json — the AURG / context-pack refs cited for THIS step (ids /
                // labels / source kinds / paths only; provider-safe, never source).
                $table->json('brain_refs')->default('[]');
                // json — the step's outcome (branch step ref / files / evidence /
                // mission_id). Empty until the node runs.
                $table->json('result')->default('{}');
                $table->timestamps();

                // Read paths: list-by-plan, filter-by-status, topological-order read.
                $table->index(['plan_id', 'status'], 'idx_atlas_obra_nodes_plan_status');
                $table->index(['plan_id', 'seq'], 'idx_atlas_obra_nodes_plan_seq');
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['atlas_obra_plans', 'atlas_obra_nodes'] as $tbl) {
                DB::statement("DROP TRIGGER IF EXISTS trg_{$tbl}_updated_at ON {$tbl};");
                DB::statement(
                    "CREATE TRIGGER trg_{$tbl}_updated_at ".
                    "BEFORE UPDATE ON {$tbl} ".
                    'FOR EACH ROW EXECUTE FUNCTION set_updated_at();'
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_obra_nodes');
        Schema::dropIfExists('atlas_obra_plans');
    }
};
