<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\Aaeos\AtlasDocsAuthorityGraphService;
use App\Services\Engineering\AtlasSoftwareTwinRuntimeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * P1 — Anticipatory Reality. simulate() must predict the immune outcome of a
 * PROPOSED, not-yet-written artifact BEFORE the write: duplicate, drift, owner.
 * Deterministic cases — the graph_id/drift cases read the real corpus + pure
 * compute (read-only); the owner case mocks the authority graph; the symbol case
 * seeds one indexed row. NO RefreshDatabase.
 */
final class AtlasSoftwareTwinPredictiveSimulatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_engineering_code_symbols');

        parent::tearDown();
    }

    public function test_predicts_duplicate_when_proposed_graph_id_collides_with_existing_canonical_doc(): void
    {
        // Read-only against the live corpus: atlas-documentation-reality-system is
        // a real canonical doc, so a proposal reusing its graph_id must collide.
        $payload = app(AtlasSoftwareTwinRuntimeService::class)->simulate([
            'kind' => 'doc',
            'graph_id' => 'atlas-documentation-reality-system',
            'slug' => 'atlas-documentation-reality-system',
            'implementation_state' => 'spec',
        ]);

        $this->assertSame(AtlasSoftwareTwinRuntimeService::PREDICTIVE_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('simulate', $payload['action']);
        $this->assertSame('would_duplicate', data_get($payload, 'prediction.verdict'));
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['writes']);
        $this->assertTrue($payload['claim_policy']['read_only']);

        $collisions = (array) data_get($payload, 'prediction.would_duplicate.graph_id_collisions');
        $this->assertNotEmpty($collisions);
        $this->assertSame('atlas-documentation-reality-system', $collisions[0]['graph_id']);
        $this->assertStringContainsString(
            'atlas-documentation-reality-system.md',
            (string) $collisions[0]['existing_doc'],
        );
        $this->assertContains('predicted_duplicate_doc', array_column($payload['blockers'], 'reason'));
    }

    public function test_predicts_drift_when_proposed_state_verified_but_evidence_refs_empty(): void
    {
        // Pure compute: verified + empty evidence_refs computes to spec -> over-claim.
        $payload = app(AtlasSoftwareTwinRuntimeService::class)->simulate([
            'kind' => 'doc',
            'graph_id' => 'atlas-zzz-fresh-drift-only-proposal',
            'slug' => 'atlas-zzz-fresh-drift-only-proposal',
            'implementation_state' => 'verified',
            'evidence_refs' => [],
        ]);

        $this->assertTrue(data_get($payload, 'prediction.would_drift'));
        $this->assertSame('would_drift', data_get($payload, 'prediction.verdict'));
        $this->assertSame('verified', data_get($payload, 'prediction.drift_detail.claimed_state'));
        $this->assertSame('spec', data_get($payload, 'prediction.drift_detail.computed_state'));
        $this->assertContains(
            'predicted_implementation_state_over_claim',
            array_column($payload['blockers'], 'reason'),
        );
    }

    public function test_predicts_clean_for_fresh_proposal_with_resolved_owner_and_no_overlap(): void
    {
        // Mock the authority graph so the owner resolves deterministically and no
        // capability/governs overlap is reported. Fresh graph_id => no collision.
        $graph = Mockery::mock(AtlasDocsAuthorityGraphService::class);
        $graph->shouldReceive('locate')->andReturn([
            'schema_version' => 'atlas.docs.locate.v1',
            'needle' => 'documentation-governance',
            'resolved' => true,
            'owner_doc_path' => 'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
            'owner_doc_id' => 'atlas-documentation-reality-system',
            'owner_basis' => 'governs_frontmatter',
            'confidence' => 100,
            'candidates' => [],
        ]);
        $this->app->instance(AtlasDocsAuthorityGraphService::class, $graph);

        $payload = app(AtlasSoftwareTwinRuntimeService::class)->simulate([
            'kind' => 'doc',
            'graph_id' => 'atlas-zzz-fresh-clean-proposal-xyz-9090',
            'slug' => 'atlas-zzz-fresh-clean-proposal-xyz-9090',
            'owner' => 'documentation-governance',
            'implementation_state' => 'spec',
        ]);

        $this->assertSame('clean', data_get($payload, 'prediction.verdict'));
        $this->assertSame('ready', $payload['status']);
        $this->assertFalse(data_get($payload, 'prediction.would_drift'));
        $this->assertFalse(data_get($payload, 'prediction.would_duplicate.duplicate'));
        $this->assertTrue(data_get($payload, 'prediction.owner.resolved'));
        $this->assertSame([], $payload['blockers']);
    }

    public function test_predicts_duplicate_for_proposed_symbol_matching_existing_indexed_class(): void
    {
        $this->createSymbolsTable();
        AtlasEngineeringCodeSymbol::query()->create([
            'symbol_type' => 'class',
            'symbol_name' => 'App\\Services\\Engineering\\AtlasDocumentationRealitySystemService',
            'file_path' => 'app/Services/Engineering/AtlasDocumentationRealitySystemService.php',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => 'predictive-test-hash-0001',
        ]);

        $payload = app(AtlasSoftwareTwinRuntimeService::class)->simulate([
            'kind' => 'symbol',
            'symbol_name' => 'AtlasDocumentationRealitySystemService',
        ]);

        $this->assertSame('would_duplicate', data_get($payload, 'prediction.verdict'));
        $this->assertSame('blocked', $payload['status']);

        $collisions = (array) data_get($payload, 'prediction.would_duplicate.symbol_collisions');
        $this->assertNotEmpty($collisions);
        $this->assertSame(
            'App\\Services\\Engineering\\AtlasDocumentationRealitySystemService',
            $collisions[0]['symbol_name'],
        );
        $this->assertSame('class', $collisions[0]['symbol_type']);
        $this->assertContains('predicted_duplicate_symbol', array_column($payload['blockers'], 'reason'));
    }

    private function createSymbolsTable(): void
    {
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::create('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('module_id')->nullable();
            $table->string('symbol_type', 60);
            $table->string('symbol_name', 300);
            $table->string('file_path', 500);
            $table->integer('line_start')->nullable();
            $table->integer('line_end')->nullable();
            $table->string('language', 40)->nullable();
            $table->text('signature')->nullable();
            $table->string('namespace', 220)->nullable();
            $table->string('parent_symbol', 300)->nullable();
            $table->string('visibility', 40)->nullable();
            $table->string('status', 32)->default('active');
            $table->string('docs_status', 40)->default('undocumented');
            $table->string('source_hash', 64);
            $table->json('related_doc_ids_json')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }
}
