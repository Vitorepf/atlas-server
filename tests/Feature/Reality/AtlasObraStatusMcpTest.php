<?php

declare(strict_types=1);

namespace Tests\Feature\Reality;

use App\Models\AtlasAurgNode;
use App\Services\Ai\AtlasMemoryPrivacyService;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Tests\TestCase;

/**
 * AOBG N3.F4 — the OPERATOR SURFACE read tool (atlas_obra_status MCP).
 *
 * Locks the tool over a REAL recorded-outcome fixture (obras recorded through the same
 * ingestion the conductor uses, on sqlite):
 *  - lists the recorded obras, each with its branch ref + certification status;
 *  - PROVIDER-BOUND is forced: a sensitive obra node is structurally excluded (the
 *    output can land in a provider prompt);
 *  - no deliver tool is exposed via MCP (commissioning spends + writes → CLI only);
 *  - honest gate: aurg_disabled when the brain flag is off.
 */
final class AtlasObraStatusMcpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migration = require database_path('migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php');
        $migration->down();
        $migration->up();

        config()->set('atlas.aurg.enabled', true);
    }

    public function test_lists_recorded_obras_with_branch_and_certification_status(): void
    {
        // Two obras recorded through the real ingestion (the F3 write-back path).
        $this->ingestion()->recordObraOutcome([
            'id' => 'obra-1',
            'intent' => 'first commissioned obra',
            'branch' => 'atlas/obra/obra-1',
            'certified' => true,
            'status' => 'certified',
            'integrated_status' => 'passed',
            'delivered_steps' => 3,
            'total_steps' => 3,
            'receipt_hash' => str_repeat('a', 64),
        ]);
        $this->ingestion()->recordObraOutcome([
            'id' => 'obra-2',
            'intent' => 'second obra, integration failed',
            'branch' => 'atlas/obra/obra-2',
            'certified' => false,
            'status' => 'needs_review',
            'integrated_status' => 'failed',
            'delivered_steps' => 2,
            'total_steps' => 2,
        ]);

        $structured = $this->callTool(['limit' => 10]);

        $this->assertTrue($structured['ok']);
        $this->assertTrue($structured['provider_bound']);
        $this->assertSame(2, $structured['count']);

        $byId = collect($structured['obras'])->keyBy('obra_id');
        $this->assertTrue($byId->has('obra-1'));
        $this->assertTrue($byId->has('obra-2'));

        $one = $byId->get('obra-1');
        $this->assertSame('atlas/obra/obra-1', $one['branch']);
        $this->assertTrue($one['certified']);
        $this->assertSame('certified', $one['status']);
        $this->assertSame('passed', $one['integrated_status']);
        $this->assertSame(3, $one['delivered_steps']);
        $this->assertSame(3, $one['total_steps']);
        $this->assertTrue($one['never_merged']);

        $two = $byId->get('obra-2');
        $this->assertSame('atlas/obra/obra-2', $two['branch']);
        $this->assertFalse($two['certified']);
        // Honest: a per-step-green obra whose integrated check failed is needs_review.
        $this->assertSame('needs_review', $two['status']);
        $this->assertSame('failed', $two['integrated_status']);
    }

    public function test_provider_bound_excludes_a_sensitive_obra(): void
    {
        $this->ingestion()->recordObraOutcome([
            'id' => 'safe-obra',
            'intent' => 'a safe obra',
            'branch' => 'atlas/obra/safe-obra',
            'certified' => true,
        ]);

        // A sensitive obra node inserted directly (defence in depth: the recorder never
        // marks an obra sensitive, but the reader must still filter it out).
        AtlasAurgNode::query()->create([
            'id' => 'obra:obra:secret-obra',
            'kind' => 'obra',
            'source_kind' => 'obra',
            'source_id' => 'secret-obra',
            'label' => '[redacted] sensitive obra',
            'provider_safe' => false,
            'sensitive' => true,
            'meta' => ['branch' => 'atlas/obra/secret-obra', 'never_merged' => true],
            'content_hash' => hash('sha256', 'secret-obra'),
        ]);

        $structured = $this->callTool([]);

        $ids = array_column($structured['obras'], 'obra_id');
        $this->assertContains('safe-obra', $ids);
        $this->assertNotContains('secret-obra', $ids, 'provider-bound surface must exclude the sensitive obra');
    }

    public function test_tool_is_listed_and_no_deliver_tool_is_exposed(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $list = $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
        $names = array_column($list['result']['tools'], 'name');

        $this->assertContains('atlas_obra_status', $names);
        // Commissioning spends + writes → it stays on the CLI, never an MCP tool.
        $this->assertNotContains('atlas_obra_deliver', $names);
        $this->assertNotContains('atlas_obra_run', $names);
    }

    public function test_honest_gate_when_brain_disabled(): void
    {
        config()->set('atlas.aurg.enabled', false);
        $structured = $this->callTool([]);
        $this->assertFalse($structured['ok']);
        $this->assertSame('aurg_disabled', $structured['error']);
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function callTool(array $arguments): array
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_obra_status', 'arguments' => $arguments],
        ]);

        return $response['result']['structuredContent'];
    }

    private function ingestion(): AtlasRealityGraphIngestionService
    {
        return new AtlasRealityGraphIngestionService(
            new CrossDomainTaxonomyMap,
            app(AtlasMemoryPrivacyService::class),
            app(AtlasCrossDomainMeshService::class),
        );
    }
}
