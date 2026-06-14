<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use App\Services\Ai\Programming\AtlasForgeMultiNodeL410ProofService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GOAL A (Phase 2): MULTI-FILE REFACTOR ROUTING. A `refactor_*` objective touching >=2 files
 * is hard-routed to the OBRA BRIDGE (operator-reviewed, never-merge) INSTEAD of the single-
 * file explorer/materializer. It NEVER produces a single-file proposal of a multi-file diff,
 * and it NEVER auto-merges anything (the bridge requires operator approval + its own L4-10
 * gate). With the flag OFF, the routing is inert (the grind is byte-identical to today).
 *
 * Uses the REAL AtlasLoopObraBridgeService (cost-free; it dispatches no provider). The
 * candidate path is reached by writing real L4-10 evidence exactly like AtlasLoopObraBridgeTest.
 */
final class AtlasLoopMultiFileRefactorRoutingTest extends TestCase
{
    private string $evidencePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        $this->evidencePath = storage_path('framework/testing/multi-file-refactor-l410-'.Str::uuid().'.json');
    }

    protected function tearDown(): void
    {
        if ($this->evidencePath !== '') {
            @File::delete($this->evidencePath);
        }
        parent::tearDown();
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'multi-file refactor routing',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    /** @return list<string> */
    private function targetFiles(): array
    {
        return [
            'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
            'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function claimedTask(string $campaignId, string $objective, array $payload, string $worker): AtlasLoopTask
    {
        return AtlasLoopTask::create([
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_CLAIMED,
            'source' => 'test',
            'self_contained' => true,
            'target_path' => $this->targetFiles()[0],
            'objective' => $objective,
            'payload' => $payload,
            'priority' => 100,
            'attempts' => 1,
            'max_attempts' => 2,
            'dedupe_key' => 'dk-'.bin2hex(random_bytes(4)),
            'claimed_by' => $worker,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addMinutes(10),
        ]);
    }

    private function grinder(): AtlasLoopTaskGrinder
    {
        return $this->app->make(AtlasLoopTaskGrinder::class);
    }

    /**
     * Mirror the proven-valid L4-10 real-execution receipt (AtlasLoopObraBridgeTest): the
     * proof requires a 6–10 node real obra run with a signed executor receipt, hermes_cli /
     * gpt-5.5, and kill/resume evidence. The obra's OWN files here are irrelevant to the
     * multi-file DETECTION (that comes from the task's allowed_files); this file only unblocks
     * the bridge's L4-10 gate so the candidate path is reachable.
     */
    private function writeRealL410Evidence(): void
    {
        $files = [
            'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
            'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
            'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
        ];
        $executorReceipt = (new \App\Services\Ai\Obra\AtlasObraReceiptStamp)->stamp([
            'obra_id' => 'obra-multi-file-refactor',
            'status' => 'done',
            'certified' => true,
            'node_count' => 6,
            'delivered_nodes' => 6,
            'provider' => 'hermes_cli',
            'model' => 'gpt-5.5',
            'resumed' => true,
            'resume_count' => 1,
            'main_untouched' => true,
            'never_merged' => true,
            'delivered_item_id' => 'L4-6',
            'delivered_files' => $files,
        ]);
        File::ensureDirectoryExists(dirname($this->evidencePath));
        File::put($this->evidencePath, json_encode([
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'status' => 'done',
            'certified' => true,
            'execution_mode' => 'real_provider_obra_run',
            'obra_id' => 'obra-multi-file-refactor',
            'node_count' => 6,
            'provider_calls_made' => true,
            'external_provider_call' => true,
            'provider' => ['name' => 'hermes_cli', 'model' => 'gpt-5.5'],
            'delivered_item_id' => 'L4-6',
            'delivered_files' => $files,
            'resumed' => true,
            'resume_count' => 1,
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['ledger:kill-real', 'ledger:resume-real'],
            ],
            'command_results' => [['command' => 'php artisan atlas:loop:morning-digest --json', 'exit_code' => 0]],
            'executor_receipt' => $executorReceipt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function test_multi_file_refactor_with_l4_10_evidence_routes_to_obra_third_terminal_status(): void
    {
        config(['atlas.loop.refactor_multi_file_via_obra' => true]);
        $this->writeRealL410Evidence();

        $campaign = $this->campaign();
        $task = $this->claimedTask($campaign->id, 'refactor reduce complexity across modules', [
            'objective_kind' => 'refactor_reduce_complexity',
            'allowed_files' => $this->targetFiles(),
            'l4_10_evidence_path' => $this->evidencePath,
        ], 'w-route');

        $result = $this->grinder()->grind($task, 'w-route', null, sys_get_temp_dir());

        // The THIRD terminal status; never a winner; ZERO proposals; the bridge never merges.
        $this->assertSame('proposal_created_obra_multi_file_refactor', $result['status'], json_encode($result['obra_bridge'] ?? []));
        $this->assertFalse($result['has_winner']);
        $this->assertSame(0, $result['proposals']);
        $this->assertTrue((bool) data_get($result, 'obra_bridge.operator_approval_required'));
        $this->assertFalse((bool) data_get($result, 'obra_bridge.provider_dispatches_now'));

        // NO proposal was created (no single-file materialize of a multi-file diff).
        $this->assertSame(0, AtlasLoopProposal::query()->count());
        // The task is terminal-DONE (graduated to the governed obra handoff).
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->fresh()->status);
    }

    public function test_blocked_bridge_drops_to_no_winner_never_single_file_materializes(): void
    {
        config(['atlas.loop.refactor_multi_file_via_obra' => true]);

        // The realistic test-env outcome: NO L4-10 real evidence => the bridge BLOCKS. The
        // grind must DROP to no_winner, NOT fall through to a single-file materialize. Routing
        // fires BEFORE the explorer, so a blocked multi-file refactor never reaches it.
        $campaign = $this->campaign();
        $task = $this->claimedTask($campaign->id, 'refactor extract shared helper', [
            'objective_kind' => 'refactor_extract_method',
            'allowed_files' => $this->targetFiles(),
        ], 'w-blocked');

        $result = $this->grinder()->grind($task, 'w-blocked', null, sys_get_temp_dir());

        $this->assertSame('no_winner', $result['status']);
        $this->assertFalse($result['has_winner']);
        $this->assertSame(0, $result['proposals']);
        $this->assertStringContainsString('obra_bridge_blocked_by_l4_10', (string) $result['reason']);
        $this->assertSame(0, AtlasLoopProposal::query()->count(), 'a blocked multi-file refactor must NOT single-file materialize');
    }

    public function test_single_file_refactor_does_not_route_to_obra(): void
    {
        config(['atlas.loop.refactor_multi_file_via_obra' => true]);
        // Force the resource gate to backpressure so the single-file path terminates cleanly
        // and deterministically (the real explorer is never run against a missing file). The
        // load-bearing check is that NO obra route was taken — backpressure happens on the
        // single-file path, never the obra route (which returns before this point).
        config(['atlas.loop.campaign.min_free_mb' => PHP_INT_MAX]);

        $campaign = $this->campaign();
        $task = $this->claimedTask($campaign->id, 'refactor one file', [
            'objective_kind' => 'refactor_reduce_complexity',
            'allowed_files' => [$this->targetFiles()[0]],
        ], 'w-single');

        $result = $this->grinder()->grind($task, 'w-single', null, sys_get_temp_dir());

        $this->assertArrayNotHasKey('obra_bridge', $result, 'a single-file refactor must NOT route to obra');
        $this->assertSame('backpressure', $result['status'], 'the single-file path ran (admit gate), not the obra route');
    }

    public function test_flag_off_never_routes_to_obra_even_for_multi_file_refactor(): void
    {
        config(['atlas.loop.refactor_multi_file_via_obra' => false]);
        config(['atlas.loop.campaign.min_free_mb' => PHP_INT_MAX]); // deterministic single-file terminus
        $this->writeRealL410Evidence();

        // OFF => the routing is inert; the single-file path runs (here: stops at the admit
        // gate), proving the grind is byte-identical to today (no obra route) even with L4-10
        // evidence present.
        $campaign = $this->campaign();
        $task = $this->claimedTask($campaign->id, 'refactor reduce complexity across modules', [
            'objective_kind' => 'refactor_reduce_complexity',
            'allowed_files' => $this->targetFiles(),
            'l4_10_evidence_path' => $this->evidencePath,
        ], 'w-off');

        $result = $this->grinder()->grind($task, 'w-off', null, sys_get_temp_dir());

        $this->assertArrayNotHasKey('obra_bridge', $result, 'flag OFF => never routes to obra');
        $this->assertSame('backpressure', $result['status'], 'OFF => the single-file path ran (admit gate), not the obra route');
    }
}
