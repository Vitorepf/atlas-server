<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiLearningProposal;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\AtlasOpenBrainWriteBackService;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * AOBG N1.F2 — the governed WRITE-BACK door (external AI feeds the brain).
 *
 * Locks the hostile-input contract over a sqlite, COST-FREE fixture (no provider call
 * anywhere — pure local DB writes). Tables are built sqlite-safe (the suite avoids
 * RefreshDatabase because some migrations are Postgres-only raw SQL):
 *  - AURG reality-graph nodes/edges (the mission/evidence write target);
 *  - ai_learning_proposals (the proposal write target, via its standalone migration);
 *  - atlas_memory_entries (to PROVE canonical memory is never mutated by a proposal).
 *
 * Asserts the HARD floor: record_outcome creates a brain node + is idempotent + never a
 * merge; propose_learning lands pending_review (NOT applied, canonical memory untouched);
 * a sensitive/secret payload is rejected; the capture gate rejects noise; fail-open when
 * the store is absent; the two MCP tools dispatch.
 */
final class AtlasOpenBrainWriteBackServiceTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAtlasMemoryEntryTable();
        $this->createAurgTables();
        $this->createLearningProposalsTable();

        config()->set('atlas.aurg.enabled', true);
        // Default the capture gate to observe (annotate + persist) so a real proposal
        // lands; the noise test flips to enforce explicitly.
        config()->set('atlas.ai.capture_quality_gate.mode', 'observe');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_learning_proposals');
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    public function test_record_outcome_creates_a_brain_node_and_is_idempotent(): void
    {
        $input = [
            'id' => 'task-42',
            'request' => 'add a per-request cache to the workspace resolver',
            'files' => ['app/Services/Foo.php', 'tests/FooTest.php'],
            'branch' => 'atlas/materialize/task-42',
            'provider' => 'claude-code',
            'delivered' => true,
            'result' => ['status' => 'passed'],
        ];

        $first = $this->service()->recordOutcome($input);

        $this->assertTrue($first['ok'], 'outcome should record into the brain');
        $this->assertFalse($first['merged'], 'an outcome is NEVER a merge to main');
        $this->assertNotNull($first['mission_node']);
        $this->assertNotNull($first['evidence_node']);

        // The mission + evidence nodes exist in the brain.
        $this->assertTrue(AtlasAurgNode::query()->where('id', $first['mission_node'])->exists());
        $this->assertTrue(AtlasAurgNode::query()->where('id', $first['evidence_node'])->exists());
        $nodesAfterFirst = AtlasAurgNode::query()->count();

        // Idempotent: re-recording the SAME outcome collapses to the SAME nodes (no dupes).
        $second = $this->service()->recordOutcome($input);
        $this->assertTrue($second['ok']);
        $this->assertSame($first['mission_node'], $second['mission_node']);
        $this->assertSame($first['evidence_node'], $second['evidence_node']);
        $this->assertSame($nodesAfterFirst, AtlasAurgNode::query()->count(), 're-recording must not create new nodes');
    }

    public function test_propose_learning_lands_pending_review_and_does_not_mutate_canonical_memory(): void
    {
        $memoryBefore = AtlasMemoryEntry::query()->count();

        $result = $this->service()->proposeLearning([
            'kind' => 'retrieval_hint',
            'summary' => 'cache the workspace resolver result per request to avoid repeated identity lookups',
            'evidence_refs' => ['app/Services/Engineering/CodeGraph/CodeGraphWorkspaceIdentity.php:42'],
            'proposed_state' => ['cache' => 'per_request', 'key' => 'workspace_id'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('pending_review', $result['status']);
        $this->assertFalse($result['applied'], 'a proposal is NEVER auto-applied');
        $this->assertFalse($result['auto_promoted'], 'a proposal is NEVER auto-promoted');
        $this->assertTrue($result['requires_human_review']);
        $this->assertNotNull($result['proposal_id']);

        // It is a PROPOSED proposal in the proposal store…
        $proposal = AiLearningProposal::query()->find($result['proposal_id']);
        $this->assertNotNull($proposal);
        $this->assertSame('proposed', $proposal->status, 'must land status=proposed, never applied/approved');
        $this->assertTrue((bool) $proposal->requires_human_review);

        // …and it did NOT write canonical memory (the hard guarantee).
        $this->assertSame($memoryBefore, AtlasMemoryEntry::query()->count(), 'untrusted input must NOT mutate canonical memory');

        // No proposal is ever applied through this surface.
        $this->assertSame(0, AiLearningProposal::query()->where('status', 'applied')->count());
        $this->assertSame(0, AiLearningProposal::query()->where('status', 'approved')->count());
    }

    public function test_sensitive_payload_is_rejected_for_both_actions(): void
    {
        $nodesBefore = AtlasAurgNode::query()->count();
        $proposalsBefore = AiLearningProposal::query()->count();

        // A self-declared SECRET outcome must never reach the brain.
        $outcome = $this->service()->recordOutcome([
            'id' => 'task-secret',
            'request' => 'rotate the prod vault key',
            'privacy_class' => 'secret',
        ]);
        $this->assertFalse($outcome['ok']);
        $this->assertSame(AtlasOpenBrainWriteBackService::REJECT_SENSITIVE, $outcome['status']);
        $this->assertFalse($outcome['merged']);

        // A self-declared SENSITIVE proposal must never reach the proposal store.
        $proposal = $this->service()->proposeLearning([
            'kind' => 'policy',
            'summary' => 'the prod credentials live in this path',
            'evidence_refs' => ['secret://vault'],
            'privacy_class' => 'sensitive',
        ]);
        $this->assertFalse($proposal['ok']);
        $this->assertSame(AtlasOpenBrainWriteBackService::REJECT_SENSITIVE, $proposal['status']);
        $this->assertFalse($proposal['applied']);

        // An explicit external_ai_allowed=false is also honoured as a hard reject.
        $blocked = $this->service()->proposeLearning([
            'kind' => 'memory',
            'summary' => 'this note is internal only and not for any provider',
            'evidence_refs' => ['internal://note'],
            'external_ai_allowed' => false,
        ]);
        $this->assertFalse($blocked['ok']);
        $this->assertSame(AtlasOpenBrainWriteBackService::REJECT_SENSITIVE, $blocked['status']);

        // Nothing crossed into either store.
        $this->assertSame($nodesBefore, AtlasAurgNode::query()->count());
        $this->assertSame($proposalsBefore, AiLearningProposal::query()->count());
    }

    public function test_capture_gate_rejects_noise(): void
    {
        // In enforce mode the capture quality gate drops smoke-test / fixture noise
        // BEFORE it pollutes the proposal store.
        config()->set('atlas.ai.capture_quality_gate.mode', 'enforce');
        $proposalsBefore = AiLearningProposal::query()->count();

        $result = $this->service()->proposeLearning([
            'kind' => 'benchmark',
            'summary' => 'ATLAS_COMPOUND_OK deterministic smoke test reply with exactly',
            'evidence_refs' => ['smoke://test'],
            'proposed_state' => ['smoke' => true],
        ]);

        $this->assertFalse($result['ok'], 'noise must be rejected by the quality gate');
        $this->assertSame(AtlasOpenBrainWriteBackService::REJECT_QUALITY, $result['status']);
        $this->assertNull($result['proposal_id'], 'rejected noise has no persisted id');

        // Nothing reached the store in enforce mode.
        $this->assertSame($proposalsBefore, AiLearningProposal::query()->count());
        $this->assertSame(0, AiLearningProposal::query()->where('payload->source', 'aobg_write_back')->count());
    }

    public function test_oversized_payload_is_rejected_not_truncated(): void
    {
        config()->set('atlas.aobg.write_back.max_files', 3);

        $result = $this->service()->recordOutcome([
            'id' => 'task-flood',
            'request' => 'touch everything',
            'files' => ['a.php', 'b.php', 'c.php', 'd.php', 'e.php'], // 5 > cap 3
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(AtlasOpenBrainWriteBackService::REJECT_OVERSIZED, $result['status']);
        $this->assertSame('too_many_touched_files', $result['reason']);
    }

    public function test_fail_open_when_the_brain_store_is_absent(): void
    {
        // Drop the AURG store → the recorder degrades to a recorded:false reason and the
        // write-back never throws (a brain outage must not break the external session).
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');

        $result = $this->service()->recordOutcome([
            'id' => 'task-no-store',
            'request' => 'do the thing',
        ]);

        $this->assertFalse($result['ok'], 'no store ⇒ honest not-recorded');
        $this->assertFalse($result['merged']);
        $this->assertContains($result['reason'], ['store_missing', 'store_unavailable', 'aurg_disabled']);
    }

    public function test_mcp_tools_are_listed_and_dispatch(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $list = $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
        $names = array_column($list['result']['tools'], 'name');
        $this->assertContains('atlas_record_outcome', $names);
        $this->assertContains('atlas_propose_learning', $names);

        // record_outcome via MCP.
        $rec = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_record_outcome', 'arguments' => [
                'id' => 'mcp-task-1', 'request' => 'add the cache', 'delivered' => true,
            ]],
        ]);
        $recStruct = $rec['result']['structuredContent'];
        $this->assertTrue($recStruct['ok']);
        $this->assertFalse($recStruct['merged']);
        $this->assertSame('atlas_record_outcome', $recStruct['tool']);

        // propose_learning via MCP.
        $prop = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_propose_learning', 'arguments' => [
                'kind' => 'retrieval_hint',
                'summary' => 'a genuinely useful learning about caching the resolver result',
                'evidence_refs' => ['app/Foo.php:1'],
            ]],
        ]);
        $propStruct = $prop['result']['structuredContent'];
        $this->assertTrue($propStruct['ok']);
        $this->assertSame('pending_review', $propStruct['status']);
        $this->assertFalse($propStruct['applied']);
        $this->assertSame('atlas_propose_learning', $propStruct['tool']);

        // Input validation: missing required fields → honest error, no write.
        $bad = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_record_outcome', 'arguments' => ['id' => 'x']],
        ]);
        $this->assertFalse($bad['result']['structuredContent']['ok']);
        $this->assertSame('id_and_request_required', $bad['result']['structuredContent']['error']);
    }

    public function test_cli_mirrors_run_json(): void
    {
        $this->artisan('atlas:aobg:record-outcome', [
            '--id' => 'cli-task-1', '--request' => 'add the cache', '--delivered' => true, '--json' => true,
        ])->assertSuccessful();

        $this->artisan('atlas:aobg:propose-learning', [
            '--kind' => 'retrieval_hint',
            '--summary' => 'a useful learning to cache the resolver result per request',
            '--evidence' => ['app/Foo.php:1'],
            '--json' => true,
        ])->assertSuccessful();

        // A sensitive declaration is rejected but still exits 0 (audited answer).
        $this->artisan('atlas:aobg:record-outcome', [
            '--id' => 'cli-secret', '--request' => 'x', '--privacy-class' => 'secret',
        ])->assertSuccessful();
    }

    // ------------------------------------------------------------------
    // fixtures (all sqlite, cost-free — no provider call)
    // ------------------------------------------------------------------

    private function service(): AtlasOpenBrainWriteBackService
    {
        return $this->app->make(AtlasOpenBrainWriteBackService::class);
    }

    private function createAurgTables(): void
    {
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');

        $migration = require database_path('migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php');
        $migration->up();
    }

    private function createLearningProposalsTable(): void
    {
        Schema::dropIfExists('ai_learning_proposals');

        // The proposals migration guards every step on Schema::hasTable, so requiring it
        // in isolation creates ONLY ai_learning_proposals (it never touches the absent
        // ai_rag_feedback_events table).
        $migration = require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php');
        $migration->up();
    }
}
