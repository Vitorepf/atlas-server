<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiLearningProposal;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasOpenBrainSessionCaptureService;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * AOBG N2.F3 — STRUCTURAL capture: the session feeds the brain AUTOMATICALLY.
 *
 * Locks the contract over a sqlite, COST-FREE fixture (no provider call anywhere — the
 * distiller is pure transcript parsing). Tables are built sqlite-safe (the suite avoids
 * RefreshDatabase because some migrations are Postgres-only raw SQL), mirroring the
 * N1.F2 write-back test (the same downstream pipeline this feeds):
 *  - AURG reality-graph nodes/edges (the mission/evidence write target);
 *  - ai_learning_proposals (the proposal write target, via its standalone migration);
 *  - atlas_memory_entries (to PROVE canonical memory is never mutated by capture).
 *
 * The non-negotiable assertions:
 *  - a session transcript records ONE outcome node + lands explicit learnings as
 *    pending_review (NOT applied; canonical memory UNCHANGED);
 *  - the capture quality gate rejects a noise / contentless session;
 *  - NO provider call (the distill is deterministic — no LLM);
 *  - fail-open (a missing transcript / store is a no-op, never a throw);
 *  - capture is idempotent on the session id (re-capture = same node, no dupes).
 */
final class AtlasOpenBrainSessionCaptureServiceTest extends TestCase
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

    public function test_session_records_outcome_node_and_lands_learnings_pending_review(): void
    {
        $memoryBefore = AtlasMemoryEntry::query()->count();

        // A Claude-Code-shaped transcript: a user prompt, two mutating tool calls, an
        // EXPLICIT file-cited learning, and a result marker.
        $lines = [
            ['type' => 'user', 'sessionId' => 'sess-abc', 'message' => ['role' => 'user', 'content' => 'add a per-request cache to the resolver']],
            ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => 'app/Services/Foo.php']],
                ['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => 'app/Services/Ignored.php']],
                ['type' => 'tool_use', 'name' => 'Write', 'input' => ['file_path' => 'tests/FooTest.php']],
            ]]],
            ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => "Done.\nATLAS-LEARNING: cache the resolver per request to avoid repeated lookups (app/Services/Foo.php:42)\nATLAS-RESULT: passed"],
            ]]],
        ];

        $result = $this->service()->captureSession(['transcript_lines' => $lines]);

        // The OUTCOME node was recorded (provider-safe, never a merge).
        $this->assertTrue($result['fed']);
        $this->assertFalse($result['merged']);
        $this->assertTrue($result['outcome']['fed']);
        $this->assertNotNull($result['outcome']['mission_node']);
        $this->assertTrue(AtlasAurgNode::query()->where('id', $result['outcome']['mission_node'])->exists());
        $this->assertTrue(AtlasAurgNode::query()->where('id', $result['outcome']['evidence_node'])->exists());

        // The touched files were the MUTATING tools only — the Read is ignored.
        $this->assertSame(2, $result['counts']['touched_files']);

        // The explicit learning landed as a PROPOSAL — pending_review, NOT applied.
        $this->assertSame(1, $result['counts']['learnings_fed']);
        $this->assertTrue($result['learnings'][0]['ok']);
        $this->assertSame('pending_review', $result['learnings'][0]['status']);
        $proposal = AiLearningProposal::query()->find($result['learnings'][0]['proposal_id']);
        $this->assertNotNull($proposal);
        $this->assertSame('proposed', $proposal->status, 'a captured learning must land status=proposed, never applied');
        $this->assertTrue((bool) $proposal->requires_human_review);

        // Canonical memory is UNTOUCHED (the hard guarantee).
        $this->assertSame($memoryBefore, AtlasMemoryEntry::query()->count(), 'capture must NOT mutate canonical memory');
        $this->assertSame(0, AiLearningProposal::query()->where('status', 'applied')->count());
        $this->assertSame(0, AiLearningProposal::query()->where('status', 'approved')->count());
    }

    public function test_contentless_session_is_a_no_op(): void
    {
        // A session with no touched files, no result, no explicit learning — nothing
        // structural to record. Must NOT mint a stub mission node (the noise lesson).
        $nodesBefore = AtlasAurgNode::query()->count();
        $proposalsBefore = AiLearningProposal::query()->count();

        $result = $this->service()->captureSession(['transcript_lines' => [
            ['type' => 'user', 'message' => ['role' => 'user', 'content' => 'hi there']],
            ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => 'Sure, how can I help?'],
            ]]],
        ]]);

        $this->assertFalse($result['fed']);
        $this->assertSame('nothing_to_capture', $result['reason']);
        $this->assertSame($nodesBefore, AtlasAurgNode::query()->count(), 'a contentless session must not create a brain node');
        $this->assertSame($proposalsBefore, AiLearningProposal::query()->count());
    }

    public function test_marked_learning_without_a_citation_is_dropped(): void
    {
        // Cite-or-omit: a LEARNING line with NO file:line/scheme citation is not a real
        // learning — it must be dropped (never injected as a proposal). The session
        // still records its outcome (it touched a file).
        $result = $this->service()->captureSession(['transcript_lines' => [
            ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => 'app/Services/Bar.php']],
                ['type' => 'text', 'text' => 'ATLAS-LEARNING: this was a good idea overall'],
            ]]],
        ]]);

        $this->assertSame(0, $result['counts']['learnings_found'], 'a marked-but-uncited learning is not captured');
        $this->assertTrue($result['outcome']['fed'], 'the outcome is still recorded from the touched file');
    }

    public function test_capture_quality_gate_rejects_noise(): void
    {
        // In enforce mode the capture quality gate drops smoke-test / fixture noise
        // BEFORE it pollutes the proposal store — even though the learning is cited.
        config()->set('atlas.ai.capture_quality_gate.mode', 'enforce');
        $proposalsBefore = AiLearningProposal::query()->count();

        $result = $this->service()->captureSession(['transcript_lines' => [
            ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'name' => 'Write', 'input' => ['file_path' => 'app/Services/Smoke.php']],
                ['type' => 'text', 'text' => 'ATLAS-LEARNING: ATLAS_COMPOUND_OK deterministic smoke test reply with exactly (smoke://test)'],
            ]]],
        ]]);

        // The learning was FOUND but the gate refused to persist it.
        $this->assertSame(1, $result['counts']['learnings_found']);
        $this->assertSame(0, $result['counts']['learnings_fed'], 'noise must be rejected by the quality gate');
        $this->assertFalse($result['learnings'][0]['ok']);
        $this->assertNull($result['learnings'][0]['proposal_id'], 'rejected noise has no persisted id');
        $this->assertSame($proposalsBefore, AiLearningProposal::query()
            ->where('payload->source', 'aobg_session_capture')->count());
    }

    public function test_capture_is_idempotent_on_the_session_id(): void
    {
        $lines = [
            ['type' => 'assistant', 'sessionId' => 'sess-idem', 'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => 'app/Services/Baz.php']],
                ['type' => 'text', 'text' => 'ATLAS-RESULT: passed'],
            ]]],
        ];

        $first = $this->service()->captureSession(['transcript_lines' => $lines]);
        $this->assertTrue($first['outcome']['fed']);
        $nodesAfterFirst = AtlasAurgNode::query()->count();

        // Re-capturing the SAME transcript (same derived session id) collapses to the
        // SAME node — capture is idempotent, never duplicating the brain.
        $second = $this->service()->captureSession(['transcript_lines' => $lines]);
        $this->assertTrue($second['outcome']['fed']);
        $this->assertSame($first['outcome']['mission_node'], $second['outcome']['mission_node']);
        $this->assertSame($nodesAfterFirst, AtlasAurgNode::query()->count(), 're-capture must not create new nodes');
    }

    public function test_self_declared_session_id_is_used_for_the_node_identity(): void
    {
        $result = $this->service()->captureSession([
            'session_id' => 'explicit-session-99',
            'transcript_lines' => [
                ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [
                    ['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => 'app/X.php']],
                ]]],
            ],
        ]);

        $this->assertSame('explicit-session-99', $result['session_id']);
        $this->assertTrue($result['outcome']['fed']);
    }

    public function test_fail_open_when_the_brain_store_is_absent(): void
    {
        // Drop the AURG store → the recorder degrades to a not-recorded reason and the
        // capture never throws (a brain outage must never break session end).
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');

        $result = $this->service()->captureSession(['transcript_lines' => [
            ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => 'app/Y.php']],
            ]]],
        ]]);

        // The outcome is honestly not-recorded; the call returned cleanly (no throw).
        $this->assertFalse($result['outcome']['fed']);
        $this->assertFalse($result['merged']);
        $this->assertContains($result['outcome']['reason'], ['store_missing', 'store_unavailable', 'aurg_disabled']);
    }

    public function test_missing_transcript_file_is_a_clean_no_op(): void
    {
        $result = $this->service()->captureSession(['transcript' => '/no/such/transcript.jsonl']);

        $this->assertFalse($result['fed']);
        $this->assertSame('nothing_to_capture', $result['reason']);
    }

    public function test_distillation_is_deterministic_no_provider_call(): void
    {
        // The whole point: NO LLM in the default path. Assert the envelope labels the
        // distill deterministic (the service makes no provider call by construction —
        // it only delegates to the local-DB write-back).
        $result = $this->service()->captureSession(['transcript_lines' => [
            ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => 'app/Z.php']],
            ]]],
        ]]);

        $this->assertSame('deterministic', $result['distill']);
        $this->assertTrue($result['provider_bound']);
    }

    public function test_cli_command_runs_json_and_exits_zero(): void
    {
        // Write a small transcript fixture to a temp file and capture it via the CLI.
        $path = tempnam(sys_get_temp_dir(), 'atlas_sc_').'.jsonl';
        $lines = [
            json_encode(['type' => 'user', 'sessionId' => 'cli-sess', 'message' => ['role' => 'user', 'content' => 'do the thing']]),
            json_encode(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => 'app/Services/Cli.php']],
                ['type' => 'text', 'text' => 'ATLAS-LEARNING: capture this via the CLI path (app/Services/Cli.php:7)'],
            ]]]),
        ];
        file_put_contents($path, implode("\n", $lines));

        try {
            $this->artisan('atlas:aobg:capture-session', ['--transcript' => $path, '--json' => true])
                ->assertSuccessful();
            // A missing transcript is also a clean exit 0 (fail-safe, never a gate).
            $this->artisan('atlas:aobg:capture-session', ['--transcript' => '/no/such.jsonl'])
                ->assertSuccessful();
            // No transcript at all is a clean exit 0 too.
            $this->artisan('atlas:aobg:capture-session')->assertSuccessful();
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------------
    // fixtures (all sqlite, cost-free — no provider call) — mirrors N1.F2
    // ------------------------------------------------------------------

    private function service(): AtlasOpenBrainSessionCaptureService
    {
        return $this->app->make(AtlasOpenBrainSessionCaptureService::class);
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

        // The proposals migration guards every step on table availability, so requiring
        // it in isolation creates ONLY ai_learning_proposals (it never touches the
        // absent ai_rag_feedback_events table).
        $migration = require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php');
        $migration->up();
    }
}
