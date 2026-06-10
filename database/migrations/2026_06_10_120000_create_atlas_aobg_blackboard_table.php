<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AOBG N2.F4 — the BLACKBOARD: multiple engines coordinate THROUGH the brain.
 *
 * N2.F1/F2/F3 made the brain INTERVENE for a single engine (PostToolUse context,
 * PreToolUse sentinel, Stop-hook structural capture). N2.F4 makes the brain the shared
 * COORDINATION surface between engines: a small append/expire table of CLAIMS of work —
 * "claude_code is editing fileX", "codex holds task T" — so when a second engine (or the
 * same engine's PreToolUse guard) is about to touch the SAME target it can SEE the
 * cross-engine claim and step around it ("codex is editing this file") instead of two
 * engines stomping each other.
 *
 * Design contract (mirrors the AURG migration's portability + privacy rules):
 *  - Plain portable SQL: runs identically on pgsql (dev) and sqlite (tests). No
 *    pgvector, no driver-specific column types. The only pgsql-specific block is the
 *    updated_at trigger, guarded by a driver check (the canonical pattern in this repo).
 *  - A claim is a compact, provider-safe ref: {workspace_id, engine, kind, target,
 *    status, claimed_at, expires_at, meta}. `target` is a file PATH or a task REF — a
 *    label, never file content. `meta` is small bounded json (notes/session-id refs).
 *  - Idempotency is structural: UNIQUE(workspace_id, engine, kind, target) so the same
 *    engine re-claiming the same target collapses onto ONE active row (no flood).
 *  - status ∈ {active, released, stale}; a claim past its expires_at is `stale` (the
 *    service lazily expires on read so a crashed engine's claim never blocks forever).
 *  - Indexes on the read paths: scope-by-workspace, conflict-lookup-by-target, and
 *    the (workspace_id, status) active-listing path.
 *
 * SAFETY: this table is COORDINATION metadata only. It never gates an edit — the F2
 * guard surfaces a conflict as an ADVISORY warn (never a block). Read/writes are local
 * DB only, ZERO provider spend.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_aobg_blackboard')) {
            Schema::create('atlas_aobg_blackboard', function (Blueprint $table): void {
                // Deterministic string id ("<engine>:<kind>:<sha1(workspace|target)>")
                // so a re-claim resolves to the same row without a lookup.
                $table->string('id', 200)->primary();
                $table->string('workspace_id', 160)->index();
                // The engine holding the claim (claude_code|codex|cursor|atlas|...).
                $table->string('engine', 40)->index();
                // What is claimed (task|file|mission).
                $table->string('kind', 20)->index();
                // The claim target — a file PATH or a task/mission REF (a label only).
                $table->string('target', 500)->index();
                // active | released | stale.
                $table->string('status', 16)->default('active')->index();
                $table->timestamp('claimed_at')->nullable();
                // When the claim auto-expires (claimed_at + ttl). A read past this
                // marks the row `stale` so a crashed engine never holds forever.
                $table->timestamp('expires_at')->nullable()->index();
                // Small bounded json (note / session ref ids only — never content).
                $table->json('meta')->default('{}');
                $table->timestamps();

                // Idempotency: one ACTIVE row per (workspace, engine, kind, target).
                $table->unique(['workspace_id', 'engine', 'kind', 'target'], 'uniq_atlas_aobg_blackboard_claim');
                // Conflict lookup ("who else holds this target?") + active listing.
                $table->index(['workspace_id', 'target', 'status'], 'idx_atlas_aobg_blackboard_target');
                $table->index(['workspace_id', 'status'], 'idx_atlas_aobg_blackboard_active');
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                DROP TRIGGER IF EXISTS trg_atlas_aobg_blackboard_updated_at ON atlas_aobg_blackboard;
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_atlas_aobg_blackboard_updated_at
                BEFORE UPDATE ON atlas_aobg_blackboard
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_aobg_blackboard');
    }
};
