<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AOBG N4.F3 — the CROSS-DOMAIN MISSION SPINE (an intent SPANS domains).
 *
 * N4 is THE ORGANISM: the same closed loop N3 ran for a software obra, generalized to ANY
 * of the 21 canonical domains. N4.F3 is the cross-domain mission: the operator declares an
 * INTENT in natural language and Atlas decomposes it (reusing the N3 plan-DAG), routes each
 * node to a DOMAIN, generates a brain-anchored DOMAIN PROPOSAL per node (validated by the
 * domain's HONEST metric), and records the proposals into the brain — cross-domain
 * COMPOUNDING (a finance proposal informs a later marketing one).
 *
 * These two tables persist the organism plan the {@see \App\Services\Ai\Organism\AtlasOrganismMissionService}
 * writes and the status command reads:
 *
 *   atlas_organism_missions — one row per commissioned intent (the mission header):
 *     {id, intent (REDACTED summary — never raw secrets), workspace_id, anchor_domain,
 *      status, meta (node_count / domains_spanned / decomposer / honesty / ceiling), ts}.
 *   atlas_organism_nodes — one row per routed node (a cross-domain step of the mission):
 *     {id, mission_id, seq, title, request, domain (canonical), sensitive, outcome
 *      ∈ {proposed, vetoed, no_handler}, veto_reason, proposal_ref, validation (json —
 *      honest-metric numbers, NEVER win-rate), actuation_gate (always requires_operator),
 *      depends_on (json node ids), ts}.
 *
 * Design contract (mirrors the obra-plan + AURG migrations' portability + privacy):
 *  - Plain portable SQL: identical on pgsql (dev) and sqlite (tests). No driver-specific
 *    column types; the only pgsql-specific block is the updated_at trigger, driver-guarded.
 *  - PRIVACY / SENSITIVE: `intent` is a REDACTED provider-safe summary; `request`/`title`
 *    are step labels. A `sensitive` node (finance/health/cyber/…) stays ON-MACHINE — the
 *    organism never crosses it to a provider; the row carries sensitive=true. `validation`
 *    is small bounded json of honest NUMBERS only — never payload, never source, never
 *    win-rate. `proposal_ref` is a stable ref to the recorded brain node.
 *  - PROPOSE-ONLY CEILING: `actuation_gate` is ALWAYS 'requires_operator'. These rows are
 *    a plan of PROPOSALS for the operator — Atlas never executes real money/orders/ad-spend/
 *    purchases/publishing. The table never touches an exchange, a wallet, or a publisher.
 *  - status columns are first-class so a reader can filter WITHOUT decoding json. mission
 *    .status ∈ {commissioned}; node.outcome ∈ {proposed, vetoed, no_handler}.
 *  - Indexes on the read paths: mission(status); node(mission_id), node(mission_id, seq),
 *    node(mission_id, domain).
 *
 * SAFETY: PLAN + PROPOSAL metadata only. No git, no merge, no push, no real-world action.
 * Writes are local DB only, ZERO provider spend (the proposers are stubbable/on-machine).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_organism_missions')) {
            Schema::create('atlas_organism_missions', function (Blueprint $table): void {
                // Deterministic mission id ("orgm-<sha256(intent|workspace)[:12]>") so a
                // re-commission of the same intent in the same workspace is idempotent.
                $table->string('id', 200)->primary();
                // REDACTED, provider-safe summary of the operator's intent (a label,
                // never raw secrets). Bounded by the service before persisting.
                $table->string('intent', 1000);
                $table->string('workspace_id', 160)->index();
                // The mission's anchor domain (the "from" side of each cross-domain crossing
                // the ARPTL veto evaluates). Canonical id.
                $table->string('anchor_domain', 40)->default('engineering');
                // commissioned (the only status — this spine plans + proposes, never executes).
                $table->string('status', 24)->default('commissioned')->index();
                // Small bounded json (node_count, domains_spanned, decomposer, honesty, ceiling).
                $table->json('meta')->default('{}');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_organism_nodes')) {
            Schema::create('atlas_organism_nodes', function (Blueprint $table): void {
                // Deterministic node id ("<mission_id>:n<seq>").
                $table->string('id', 240)->primary();
                $table->string('mission_id', 200)->index();
                // Topological sequence (0-based): the dependency-respecting order.
                $table->integer('seq');
                // A short human label for the step.
                $table->string('title', 300);
                // The natural-language step routed to a domain (a request, not source).
                $table->text('request');
                // The canonical domain this node was routed to (via OrganismDomainRouter +
                // CrossDomainTaxonomyMap). Never a fabricated label.
                $table->string('domain', 40)->index();
                // true ⇒ a sensitive domain (finance/health/cyber/…) — stays ON-MACHINE.
                $table->boolean('sensitive')->default(false);
                // proposed | vetoed | no_handler — the per-node outcome.
                $table->string('outcome', 16)->default('proposed');
                // When vetoed: the ARPTL veto reason (provider-safe label). Null otherwise.
                $table->string('veto_reason', 200)->nullable();
                // Stable ref to the recorded brain proposal node (when outcome=proposed).
                $table->string('proposal_ref', 240)->nullable();
                // json — the honest-metric verdict NUMBERS only (metric/value/passed/method).
                // NEVER win-rate, never payload, never source.
                $table->json('validation')->default('{}');
                // ALWAYS 'requires_operator' — the propose-only ceiling, stored per node.
                $table->string('actuation_gate', 32)->default('requires_operator');
                // json array of node ids (in THIS mission) this node depends on.
                $table->json('depends_on')->default('[]');
                $table->timestamps();

                // Read paths: list-by-mission, topological-order read, filter-by-domain.
                $table->index(['mission_id', 'seq'], 'idx_atlas_organism_nodes_mission_seq');
                $table->index(['mission_id', 'domain'], 'idx_atlas_organism_nodes_mission_domain');
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['atlas_organism_missions', 'atlas_organism_nodes'] as $tbl) {
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
        Schema::dropIfExists('atlas_organism_nodes');
        Schema::dropIfExists('atlas_organism_missions');
    }
};
