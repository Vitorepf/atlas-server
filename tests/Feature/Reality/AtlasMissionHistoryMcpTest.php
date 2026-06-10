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
 * S2.F3 — the CLOSED MISSION LOOP read surface (atlas_mission_history MCP tool).
 *
 * Locks the tool over a REAL recorded-outcome fixture (missions recorded through
 * the same ingestion the loop uses, on sqlite):
 *  - lists the recorded missions, each with its branch ref + evidence status;
 *  - PROVIDER-BOUND is forced: a sensitive mission node is structurally excluded
 *    (the output can land in a provider prompt);
 *  - no deliver tool is exposed via MCP (delivering spends + writes → CLI only);
 *  - honest gate: aurg_disabled when the brain flag is off.
 */
final class AtlasMissionHistoryMcpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migration = require database_path('migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php');
        $migration->down();
        $migration->up();

        config()->set('atlas.aurg.enabled', true);
        config()->set('atlas.mission.record_outcome_enabled', true);
    }

    public function test_lists_recorded_missions_with_branch_and_evidence_status(): void
    {
        // Two delivered missions recorded through the real ingestion (the loop's path).
        $this->ingestion()->recordMissionOutcome([
            'id' => 'hist-1',
            'request' => 'first delivered mission',
            'branch' => 'atlas/materialize/hist-1',
            'delivered' => true,
            'provider' => 'codex_cli',
            'receipt' => str_repeat('a', 64),
            'files' => ['app/Generated/One.php'],
            'measure' => ['ok' => true],
        ]);
        $this->ingestion()->recordMissionOutcome([
            'id' => 'hist-2',
            'request' => 'second delivered mission',
            'branch' => 'atlas/materialize/hist-2',
            'delivered' => true,
            'provider' => 'hermes',
            'files' => ['app/Generated/Two.php'],
        ]);

        $structured = $this->callTool(['limit' => 10]);

        $this->assertTrue($structured['ok']);
        $this->assertTrue($structured['provider_bound']);
        $this->assertSame(2, $structured['count']);

        $byId = collect($structured['missions'])->keyBy('mission_id');
        $this->assertTrue($byId->has('hist-1'));
        $this->assertTrue($byId->has('hist-2'));

        $one = $byId->get('hist-1');
        $this->assertSame('atlas/materialize/hist-1', $one['branch']);
        $this->assertTrue($one['delivered']);
        $this->assertSame('codex_cli', $one['provider']);
        $this->assertSame('passed', $one['status']);   // measure ok=true → passed
        $this->assertTrue($one['never_merged']);
        $this->assertContains('app/Generated/One.php', $one['touched_paths']);

        $two = $byId->get('hist-2');
        $this->assertSame('atlas/materialize/hist-2', $two['branch']);
        // No measure was recorded → honest 'delivered' status (not faked pass).
        $this->assertSame('delivered', $two['status']);
    }

    public function test_provider_bound_excludes_a_sensitive_mission(): void
    {
        // A safe recorded mission...
        $this->ingestion()->recordMissionOutcome([
            'id' => 'safe-1',
            'request' => 'a safe mission',
            'branch' => 'atlas/materialize/safe-1',
            'delivered' => true,
        ]);

        // ...and a sensitive mission node inserted directly (defence in depth: the
        // recorder never marks a mission sensitive, but the reader must still filter).
        AtlasAurgNode::query()->create([
            'id' => 'mission:mission:secret-1',
            'kind' => 'mission',
            'source_kind' => 'mission',
            'source_id' => 'secret-1',
            'label' => '[redacted] sensitive mission',
            'provider_safe' => false,
            'sensitive' => true,
            'meta' => ['branch' => 'atlas/materialize/secret-1', 'never_merged' => true],
            'content_hash' => hash('sha256', 'secret-1'),
        ]);

        $structured = $this->callTool([]);

        $ids = array_column($structured['missions'], 'mission_id');
        $this->assertContains('safe-1', $ids);
        $this->assertNotContains('secret-1', $ids, 'provider-bound surface must exclude the sensitive mission');
    }

    public function test_tool_is_listed_and_no_deliver_tool_is_exposed(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $list = $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
        $names = array_column($list['result']['tools'], 'name');

        $this->assertContains('atlas_mission_history', $names);
        // Deliver spends + writes → it stays on the CLI, never an MCP tool.
        $this->assertNotContains('atlas_mission_deliver', $names);
        $this->assertNotContains('atlas_mission_run', $names);
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
            'params' => ['name' => 'atlas_mission_history', 'arguments' => $arguments],
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
