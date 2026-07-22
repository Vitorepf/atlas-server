<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context\Retrieval;

use App\Services\Ai\AcosMax\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Context\Retrieval\AtlasCodeSymbolEmbeddingCoverageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MAXA-06 fase 2 — code_symbol_embeddings substrate + coverage RULER (freeze + provenance + stale).
 *
 * Fase 2 (§168 do plano): SEPARATE `atlas_code_symbol_embeddings` table
 * (halfvec + HNSW pgvector, provenance on ALL drivers) so the 290k+ code
 * symbols never carry a nullable halfvec column, plus incremental re-embed
 * by `source_hash`. This slice lands the SUBSTRATE (table + ruler + registry);
 * the actual switch that promotes code embeddings into the retrieval hot
 * path stays default-OFF (per plan: "fase 2 (switch default-OFF)"). The
 * backfill and consumer wiring are follow-up work.
 *
 * Case negativo (pétreo): a symbol whose source_hash drifted from the
 * embedding's `embedded_content_hash` counts as STALE, not covered — the
 * incremental re-embed target. If the ruler counted this as covered, the
 * plan's "count(embedding)==count(*) AFTER source changes" would be a lie.
 */
final class Maxa06Fase2CodeSymbolEmbeddingCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function test_freeze_payload_is_deterministic_and_author_not_equal_judge(): void
    {
        $freeze = AtlasCodeSymbolEmbeddingCoverageService::freezePayload();

        $this->assertSame('measure_freeze', $freeze['kind']);
        $this->assertSame('atlas.code_symbol_embedding_coverage.v1', $freeze['measure_id']);
        $this->assertSame('code_symbol_embedding_coverage.v1', $freeze['formula_version']);
        $this->assertNotSame($freeze['author_engine_id'], $freeze['judge_engine_id']);
        $this->assertSame(1.0, $freeze['thresholds']['target_coverage_ratio']);
        $this->assertSame('active_symbols_only', $freeze['thresholds']['scope']);
        $this->assertSame('off', $freeze['thresholds']['default_switch']);
    }

    public function test_reader_reports_insufficient_signal_when_no_active_symbols(): void
    {
        $payload = (new AtlasCodeSymbolEmbeddingCoverageService)->report();
        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame('no_active_code_symbols', $payload['reason']);
        $this->assertSame(0, $payload['aggregate']['active_symbols']);
        $this->assertNull($payload['aggregate']['coverage_ratio']);
    }

    public function test_symbols_without_embedding_row_are_missing_not_covered(): void
    {
        $this->makeSymbol('s1', 'src-a');
        $this->makeSymbol('s2', 'src-b');

        $payload = (new AtlasCodeSymbolEmbeddingCoverageService)->report();

        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame('no_symbols_embedded_yet', $payload['reason']);
        $this->assertSame(2, $payload['aggregate']['active_symbols']);
        $this->assertSame(0, $payload['aggregate']['covered_count']);
        $this->assertSame(2, $payload['aggregate']['missing_count']);
        $this->assertSame(0, $payload['aggregate']['stale_count']);
    }

    public function test_matched_provenance_counts_as_covered(): void
    {
        $symbolA = $this->makeSymbol('s1', 'src-a');
        $symbolB = $this->makeSymbol('s2', 'src-b');
        $this->makeEmbedding($symbolA, 'jina-code', 'src-a');
        $this->makeEmbedding($symbolB, 'jina-code', 'src-b');

        $payload = (new AtlasCodeSymbolEmbeddingCoverageService)->report();

        $this->assertSame('ok', $payload['status']);
        $this->assertNull($payload['reason']);
        $this->assertSame(2, $payload['aggregate']['covered_count']);
        $this->assertSame(0, $payload['aggregate']['stale_count']);
        $this->assertSame(0, $payload['aggregate']['missing_count']);
        $this->assertSame(1.0, $payload['aggregate']['coverage_ratio']);
    }

    public function test_case_negativo_drifted_source_hash_counts_as_stale_not_covered(): void
    {
        // Symbol was embedded against 'src-old' but code changed and now hashes to 'src-new'.
        $stale = $this->makeSymbol('s-stale', 'src-new');
        $this->makeEmbedding($stale, 'jina-code', 'src-old');

        // Another symbol is properly covered.
        $fresh = $this->makeSymbol('s-fresh', 'src-x');
        $this->makeEmbedding($fresh, 'jina-code', 'src-x');

        $payload = (new AtlasCodeSymbolEmbeddingCoverageService)->report();

        $this->assertSame('partial_coverage', $payload['status']);
        $this->assertSame('symbols_awaiting_backfill_or_re_embed', $payload['reason']);
        $this->assertSame(2, $payload['aggregate']['active_symbols']);
        $this->assertSame(1, $payload['aggregate']['covered_count']);
        $this->assertSame(1, $payload['aggregate']['stale_count']);
        $this->assertSame(0, $payload['aggregate']['missing_count']);
        $this->assertSame(0.5, $payload['aggregate']['coverage_ratio']);
    }

    public function test_archived_symbols_are_excluded_from_denominator(): void
    {
        $active = $this->makeSymbol('s-live', 'src-a');
        $archived = $this->makeSymbol('s-archived', 'src-b', status: 'archived', archivedAt: now()->toIso8601String());
        $this->makeEmbedding($active, 'jina-code', 'src-a');
        // Archived symbol embedding row is irrelevant to the denominator.
        $this->makeEmbedding($archived, 'jina-code', 'src-b');

        $payload = (new AtlasCodeSymbolEmbeddingCoverageService)->report();

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, $payload['aggregate']['active_symbols']);
        $this->assertSame(1, $payload['aggregate']['covered_count']);
    }

    public function test_series_registered_in_elev20s_registry(): void
    {
        $registry = new AcosMaxMeasureSeriesRegistry;
        $this->assertContains('atlas.code_symbol_embedding_coverage.v1', $registry->seriesIds());
    }

    private function createTables(): void
    {
        Schema::create('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('symbol_type', 60);
            $table->string('symbol_name', 300);
            $table->string('file_path', 500);
            $table->string('status', 32)->default('active');
            $table->string('source_hash', 64);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_code_symbol_embeddings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('symbol_id')->index();
            $table->string('symbol_source_hash', 64);
            $table->string('embedding_model', 120);
            $table->string('embedded_content_hash', 64);
            $table->timestamp('embedded_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->unique(['symbol_id', 'embedding_model']);
        });
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_code_symbol_embeddings');
        Schema::dropIfExists('atlas_engineering_code_symbols');
    }

    private function makeSymbol(
        string $name,
        string $sourceHash,
        string $status = 'active',
        ?string $archivedAt = null,
    ): string {
        $id = (string) Str::uuid();
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => $id,
            'symbol_type' => 'method',
            'symbol_name' => $name,
            'file_path' => 'app/'.$name.'.php',
            'status' => $status,
            'source_hash' => $sourceHash,
            'archived_at' => $archivedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeEmbedding(string $symbolId, string $model, string $embeddedHash): void
    {
        DB::table('atlas_code_symbol_embeddings')->insert([
            'id' => (string) Str::uuid(),
            'symbol_id' => $symbolId,
            'symbol_source_hash' => $embeddedHash,
            'embedding_model' => $model,
            'embedded_content_hash' => $embeddedHash,
            'embedded_at' => now(),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
