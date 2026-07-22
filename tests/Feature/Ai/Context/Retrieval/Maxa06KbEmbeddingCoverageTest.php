<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context\Retrieval;

use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxLedgerRotationRegistry;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Context\Retrieval\AtlasKnowledgeItemEmbeddingCoverageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MAXA-06 fase 1 — KB items embedding coverage RULER (freeze + provenance + stale).
 *
 * Fase 1 aceite (plan): psql count(embedding) == count(*) on knowledge items,
 * ≥10 KB queries where semantic hit@5 > lexical baseline.
 *
 * This slice lands the RULER (freeze + coverage service + registry) that fase-1
 * backfill will consume. sqlite tests exercise the provenance-based coverage
 * (embedding_model + embedded_content_hash both non-null AND embedded_content_hash
 * matches current content_hash). The `embedding` halfvec column + HNSW index
 * are pgvector-only (migration is no-op on sqlite by design).
 *
 * Case negativo (pétreo): an item whose content_hash drifted from
 * embedded_content_hash counts as STALE, not covered — MAXA-03 incremental
 * re-embed target. If the ruler counted this as covered, MAXA-06 fase-1 acceptance
 * ("count(embedding)==count(*)" AFTER content changes) would be a lie.
 */
final class Maxa06KbEmbeddingCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dropTable();
        $this->createTable();
    }

    protected function tearDown(): void
    {
        $this->dropTable();
        parent::tearDown();
    }

    public function test_freeze_payload_is_deterministic_and_author_not_equal_judge(): void
    {
        $freeze = AtlasKnowledgeItemEmbeddingCoverageService::freezePayload();

        $this->assertSame('measure_freeze', $freeze['kind']);
        $this->assertSame('atlas.kb_embedding_coverage.v1', $freeze['measure_id']);
        $this->assertSame('kb_embedding_coverage.v1', $freeze['formula_version']);
        $this->assertNotSame($freeze['author_engine_id'], $freeze['judge_engine_id']);
        $this->assertSame(1.0, $freeze['thresholds']['target_coverage_ratio']);
        $this->assertSame('active_items_only', $freeze['thresholds']['scope']);
        $this->assertSame('embedded_content_hash != content_hash', $freeze['thresholds']['stale_definition']);

        $again = AtlasKnowledgeItemEmbeddingCoverageService::freezePayload();
        $this->assertSame($freeze, $again);
    }

    public function test_reader_reports_insufficient_signal_when_no_active_items(): void
    {
        $service = new AtlasKnowledgeItemEmbeddingCoverageService;
        $payload = $service->report();

        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame('no_active_knowledge_items', $payload['reason']);
        $this->assertSame(0, $payload['aggregate']['active_items']);
        $this->assertNull($payload['aggregate']['coverage_ratio']);
    }

    public function test_missing_provenance_is_not_counted_as_covered(): void
    {
        $this->makeItem('slug-missing', 'hash-A', null, null);
        $this->makeItem('slug-missing-partial', 'hash-B', 'fastembed-bge-small', null);

        $service = new AtlasKnowledgeItemEmbeddingCoverageService;
        $payload = $service->report();

        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame('no_items_embedded_yet', $payload['reason']);
        $this->assertSame(2, $payload['aggregate']['active_items']);
        $this->assertSame(0, $payload['aggregate']['covered_count']);
        $this->assertSame(2, $payload['aggregate']['missing_count']);
        $this->assertSame(0, $payload['aggregate']['stale_count']);
    }

    public function test_matched_provenance_counts_as_covered(): void
    {
        $this->makeItem('slug-ok', 'hash-A', 'fastembed-bge-small', 'hash-A');
        $this->makeItem('slug-ok-2', 'hash-B', 'fastembed-bge-small', 'hash-B');

        $service = new AtlasKnowledgeItemEmbeddingCoverageService;
        $payload = $service->report();

        $this->assertSame('ok', $payload['status']);
        $this->assertNull($payload['reason']);
        $this->assertSame(2, $payload['aggregate']['active_items']);
        $this->assertSame(2, $payload['aggregate']['covered_count']);
        $this->assertSame(0, $payload['aggregate']['stale_count']);
        $this->assertSame(0, $payload['aggregate']['missing_count']);
        $this->assertSame(1.0, $payload['aggregate']['coverage_ratio']);
    }

    public function test_case_negativo_drifted_content_hash_counts_as_stale_not_covered(): void
    {
        // Item was embedded against 'old-hash' but doc changed and now hashes to 'new-hash'.
        // MAXA-03 requires this to be re-embedded — it MUST NOT count as covered.
        $this->makeItem('slug-stale', 'hash-new', 'fastembed-bge-small', 'hash-old');

        // Another item is properly covered.
        $this->makeItem('slug-fresh', 'hash-X', 'fastembed-bge-small', 'hash-X');

        $service = new AtlasKnowledgeItemEmbeddingCoverageService;
        $payload = $service->report();

        $this->assertSame('partial_coverage', $payload['status']);
        $this->assertSame('items_awaiting_backfill_or_re_embed', $payload['reason']);
        $this->assertSame(2, $payload['aggregate']['active_items']);
        $this->assertSame(1, $payload['aggregate']['covered_count']);
        $this->assertSame(1, $payload['aggregate']['stale_count']);
        $this->assertSame(0, $payload['aggregate']['missing_count']);
        $this->assertSame(0.5, $payload['aggregate']['coverage_ratio']);
    }

    public function test_archived_items_are_excluded_from_denominator(): void
    {
        $this->makeItem('slug-live', 'hash-A', 'fastembed-bge-small', 'hash-A');
        $this->makeItem('slug-archived', 'hash-B', null, null, 'archived', now());

        $service = new AtlasKnowledgeItemEmbeddingCoverageService;
        $payload = $service->report();

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, $payload['aggregate']['active_items']);
        $this->assertSame(1, $payload['aggregate']['covered_count']);
    }

    public function test_series_registered_in_elev20s_registry_with_rotation_policy(): void
    {
        $registry = new AcosMaxMeasureSeriesRegistry;
        $this->assertContains('MAXA-06', $registry->sliceIds());
        $this->assertContains('atlas.kb_embedding_coverage.v1', $registry->seriesIds());

        $rotation = new AcosMaxLedgerRotationRegistry;
        $policy = $rotation->policyFor('atlas.kb_embedding_coverage.v1');
        $this->assertNotNull($policy);
        $this->assertSame('rotate_hybrid', $policy['mode']);
    }

    public function test_cli_command_emits_json(): void
    {
        $this->artisan('atlas:memory:kb-embedding-coverage', ['--json' => true])->assertExitCode(0);
    }

    private function createTable(): void
    {
        Schema::create('atlas_engineering_knowledge_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 160)->unique();
            $table->string('title', 220);
            $table->string('category', 80);
            $table->string('status', 32)->default('active');
            $table->unsignedSmallInteger('priority')->default(50);
            $table->string('source_type', 80)->default('canonical_doc');
            $table->text('canonical_path');
            $table->string('source_hash', 64);
            $table->string('content_hash', 64);
            $table->text('summary')->nullable();
            $table->text('body_excerpt')->nullable();
            $table->json('tags_json')->default('[]');
            $table->json('related_paths_json')->default('[]');
            $table->json('capabilities_json')->default('[]');
            $table->json('decisions_json')->default('[]');
            $table->json('maintenance_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('embedding_model', 120)->nullable();
            $table->string('embedded_content_hash', 64)->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();
        });
    }

    private function dropTable(): void
    {
        Schema::dropIfExists('atlas_engineering_knowledge_items');
    }

    private function makeItem(
        string $slug,
        string $contentHash,
        ?string $embeddingModel,
        ?string $embeddedContentHash,
        string $status = 'active',
        $archivedAt = null,
    ): AtlasEngineeringKnowledgeItem {
        return AtlasEngineeringKnowledgeItem::query()->create([
            'id' => (string) Str::uuid(),
            'slug' => $slug,
            'title' => 'Test '.$slug,
            'category' => 'engineering_knowledge',
            'status' => $status,
            'priority' => 50,
            'source_type' => 'canonical_doc',
            'canonical_path' => 'docs/engineering-knowledge-base/'.$slug.'.md',
            'source_hash' => hash('sha256', $slug),
            'content_hash' => $contentHash,
            'summary' => 'summary of '.$slug,
            'body_excerpt' => 'body of '.$slug,
            'tags_json' => [],
            'related_paths_json' => [],
            'capabilities_json' => [],
            'decisions_json' => [],
            'maintenance_json' => [],
            'metadata' => [],
            'indexed_at' => now(),
            'last_verified_at' => now(),
            'archived_at' => $archivedAt,
            'embedding_model' => $embeddingModel,
            'embedded_content_hash' => $embeddedContentHash,
            'embedded_at' => $embeddingModel !== null ? now() : null,
        ]);
    }
}
