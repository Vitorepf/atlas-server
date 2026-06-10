<?php

use App\Services\Ai\Organism\AtlasOrganismActuationGate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AOBG N4.F4 — HARDEN the propose-only boundary: the APPEND-ONLY ACTUATION AUDIT.
 *
 * THE LOAD-BEARING SAFETY OF N4 is that actuate() is PROPOSE-ONLY — it RECORDS + returns
 * 'requires_operator' and performs ZERO real-world side effect (no money/order/transfer/
 * publish/purchase). F4 routes EVERY actuation attempt through a single gate
 * ({@see AtlasOrganismActuationGate}) that writes one row here per
 * attempt, so the operator can prove — per domain — exactly which proposals Atlas was asked to
 * actuate and that NONE was executed.
 *
 * atlas_organism_actuations — one row per actuate() attempt (an append-only audit event):
 *   {id (per-attempt unique receipt), proposal_ref (stable ref to the recorded proposal),
 *    domain (canonical), sensitive, status (ALWAYS requires_operator — the ceiling),
 *    instructions (REDACTED operator-facing label), ceiling, ts}.
 *
 * Design contract (mirrors the organism-mission + AURG migrations' portability + privacy):
 *  - Plain portable SQL: identical on pgsql (dev) and sqlite (tests). No driver-specific
 *    column types; the only pgsql-specific block is the updated_at trigger, driver-guarded.
 *  - PRIVACY / SENSITIVE: NO payload, NO source, NO secrets — only provider-safe LABELS +
 *    the stable proposal ref + the honest gate status. A sensitive proposal stays ON-MACHINE
 *    and is stored sensitive=true.
 *  - PROPOSE-ONLY CEILING: `status` is ALWAYS 'requires_operator'. These rows are an AUDIT of
 *    PROPOSALS handed back to the operator — Atlas never executes real money/orders/ad-spend/
 *    purchases/publishing. The table never touches an exchange, a wallet, or a publisher.
 *  - APPEND-ONLY: each attempt is its own event row (re-actuating appends another receipt).
 *  - Indexes on the read paths: (domain), (created_at) for the recent-first review surface.
 *
 * SAFETY: AUDIT metadata only. No git, no merge, no push, no real-world action. Writes are
 * local DB only, ZERO provider spend.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_organism_actuations')) {
            Schema::create('atlas_organism_actuations', function (Blueprint $table): void {
                // Per-attempt unique receipt id ("actr:<domain>:<hash>") — append-only audit.
                $table->string('id', 240)->primary();
                // Stable ref to the recorded proposal this attempt actuated.
                $table->string('proposal_ref', 240)->index();
                // The canonical domain (finance/marketing/…). Never a fabricated label.
                $table->string('domain', 40)->index();
                // true ⇒ a sensitive domain (finance/health/cyber/…) — stayed ON-MACHINE.
                $table->boolean('sensitive')->default(false);
                // ALWAYS 'requires_operator' — the propose-only ceiling, recorded per attempt.
                $table->string('status', 32)->default('requires_operator');
                // The REDACTED operator-facing instruction label (what the OPERATOR does).
                $table->string('instructions', 2000)->nullable();
                // The declared propose-only ceiling label.
                $table->string('ceiling', 64)->default('propose_only:requires_operator');
                $table->timestamps();

                // Read path: recent-first per domain for the operator review surface.
                $table->index(['domain', 'created_at'], 'idx_atlas_organism_actuations_domain_ts');
                $table->index('created_at', 'idx_atlas_organism_actuations_ts');
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_atlas_organism_actuations_updated_at ON atlas_organism_actuations;');
            DB::statement(
                'CREATE TRIGGER trg_atlas_organism_actuations_updated_at '.
                'BEFORE UPDATE ON atlas_organism_actuations '.
                'FOR EACH ROW EXECUTE FUNCTION set_updated_at();'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_organism_actuations');
    }
};
