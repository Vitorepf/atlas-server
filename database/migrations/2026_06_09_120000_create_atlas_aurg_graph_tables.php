<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AURG Phase-2 — the fused-store tables PROMISED by the canonical
 * {@see \App\Services\Ai\Reality\AtlasRealityGraphSnapshotBuilderService} docblock
 * ("Phase 2: Persistencia (atlas_aurg_nodes + atlas_aurg_edges)").
 *
 * Design contract (Salto 1 / F1):
 *  - The brain is the CROSS-LAYER graph (federation). Intra-layer detail stays in
 *    the 5 source read-models (memory / code-intelligence / cross-domain / evidence
 *    ledger / strategic reality). Nodes are compact provider-safe REFS
 *    (source_kind + source_id + redacted label) — hundreds to low thousands of rows,
 *    never a copy of the sources (no 113k symbols, no payloads, no raw memory text).
 *  - Plain portable SQL: runs identically on pgsql (dev, 127.0.0.1:5433) and sqlite
 *    (tests). NO pgvector columns here — semantic seeding reuses the EXISTING memory
 *    vectors through AtlasMemoryVectorSearchService.
 *  - `id` is a deterministic string key ("<source_kind>:<kind>:<source_id>") so
 *    ingestion upserts are idempotent and edges can reference endpoints without
 *    lookups. UNIQUE (source_kind, source_id, kind) enforces one node per source row.
 *  - Privacy is structural: `provider_safe` + `sensitive` are first-class columns so
 *    every downstream reader can filter WITHOUT decoding meta json.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_aurg_nodes')) {
            Schema::create('atlas_aurg_nodes', function (Blueprint $table): void {
                // Deterministic node key, e.g. "memory:memory_entry:<uuid>",
                // "code:module:<workspace>/<slug>", "domain:domain:finance".
                $table->string('id', 300)->primary();
                // Builder taxonomy kind (workspace|project|mission|work_order|obra|
                // evidence|doc|memory_entry|source_packet|module|domain|reality_entity).
                $table->string('kind', 40)->index();
                // Which of the 5 fused read-models this ref points into.
                $table->string('source_kind', 20)->index();
                // Primary key / stable key of the row in the source read-model.
                $table->string('source_id', 220);
                // Provider-safe label (memory labels come from the ALREADY-redacted
                // provider projection; never raw text).
                $table->string('label', 220);
                $table->string('workspace_id', 160)->nullable()->index();
                $table->boolean('provider_safe')->default(false)->index();
                $table->boolean('sensitive')->default(false)->index();
                // Small bounded json (type/scope/slug/root_path/ids-only refs).
                $table->json('meta')->default('{}');
                $table->string('content_hash', 64)->index();
                $table->timestamps();

                $table->unique(['source_kind', 'source_id', 'kind'], 'uniq_atlas_aurg_nodes_source');
                $table->index(['source_kind', 'kind'], 'idx_atlas_aurg_nodes_source_kind');
            });
        }

        if (! Schema::hasTable('atlas_aurg_edges')) {
            Schema::create('atlas_aurg_edges', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('from_node_id', 300)->index();
                $table->string('to_node_id', 300)->index();
                // Builder taxonomy edge kind (belongs_to|depends_on|generated|
                // references|supersedes|proves).
                $table->string('kind', 40)->index();
                // Which ingestion source / deterministic linker produced the edge
                // (e.g. code_ingest, strategic_ingest, linker_memory_code).
                $table->string('source', 60)->index();
                // Deterministic confidence ladder (NOT model output, never faked):
                //   1.0 — exact id match (FK rows, taxonomy-resolved domain ids,
                //         mesh topology rules, exact path equality);
                //   0.7 — derived path/name match (path-prefix under a module
                //         root_path, label token equal to a module slug).
                // No other values are emitted by F1 ingestion.
                $table->float('confidence')->default(1.0);
                $table->json('meta')->default('{}');
                $table->timestamps();

                $table->unique(['from_node_id', 'to_node_id', 'kind'], 'uniq_atlas_aurg_edges_triple');
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['atlas_aurg_nodes', 'atlas_aurg_edges'] as $table) {
                DB::statement(<<<SQL
                    DROP TRIGGER IF EXISTS trg_{$table}_updated_at ON {$table};
                SQL);
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
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');
    }
};
