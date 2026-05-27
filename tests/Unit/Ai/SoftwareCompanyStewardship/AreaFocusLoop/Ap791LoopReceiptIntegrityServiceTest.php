<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousLoopReceiptIntegrityService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityRanker;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AP-791 · every loop cycle must be auditable (pre/post inbox, merge hash, replay,
 * next_action) and a cycle must never merge without operator-visible evidence.
 */
final class Ap791LoopReceiptIntegrityServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap791_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AutonomousLoopReceiptIntegrityService
    {
        return new AutonomousLoopReceiptIntegrityService();
    }

    // --- Pure receipt / gate behavior -------------------------------------

    public function test_pre_merge_gate_blocks_without_pre_merge_inbox(): void
    {
        $gate = $this->service()->preMergeGate([
            'inbox_item_id' => null,
            'result_bridge_id' => '',
        ]);

        $this->assertFalse($gate['merge_allowed']);
        $this->assertSame(AutonomousLoopReceiptIntegrityService::PRE_MERGE_INBOX_REQUIRED, $gate['reason']);

        $ok = $this->service()->preMergeGate(['result_bridge_id' => 'srrb_1', 'inbox_item_id' => null]);
        $this->assertTrue($ok['merge_allowed']);
    }

    public function test_blocked_cycle_receipt_has_blocker_and_next_action(): void
    {
        $receipt = $this->service()->receiptFor([
            'cycle_id' => 'c1',
            'final_status' => 'blocked',
            'blockers' => ['forge_obra_required'],
            'selected_finding' => ['finding_id' => 'f1', 'title' => 'Forge work'],
        ], ['session_id' => 'aess_1']);

        $this->assertSame(AutonomousLoopReceiptIntegrityService::STATE_BLOCKED, $receipt['lifecycle_state']);
        $this->assertFalse($receipt['completed']);
        $this->assertContains('forge_obra_required', $receipt['blockers']);
        $this->assertNotSame('', $receipt['next_action']);
        $this->assertNotSame('', $receipt['replay_command']);
        $this->assertSame(AutonomousLoopReceiptIntegrityService::INTEGRITY_OK, $receipt['integrity']);
    }

    public function test_failed_validation_cycle_is_failed_not_blocked(): void
    {
        $receipt = $this->service()->receiptFor([
            'cycle_id' => 'c1',
            'final_status' => 'blocked',
            'blockers' => ['validation_failed'],
            'selected_finding' => ['finding_id' => 'f1', 'title' => 'Broken fix'],
            'validation' => ['passed' => false, 'commands' => ['php artisan test']],
        ], ['session_id' => 'aess_1']);

        $this->assertSame(AutonomousLoopReceiptIntegrityService::STATE_FAILED, $receipt['lifecycle_state']);
        $this->assertSame(['validation_failed'], $receipt['failure_reasons']);
        $this->assertSame('failed', $receipt['validation']['status']);
    }

    public function test_merged_cycle_receipt_has_post_merge_receipt_and_hash(): void
    {
        $receipt = $this->service()->receiptFor([
            'cycle_id' => 'c1',
            'final_status' => 'cycle_completed',
            'merge_performed' => true,
            'selected_finding' => ['finding_id' => 'f1', 'title' => 'Real merge'],
            'branch_ref' => 'atlas/area-focus/x',
            'worktree_path' => '/tmp/wt',
            'sandbox_id' => 'afsb_1',
            'inbox_item_id' => 'inbox_1',
            'result_bridge_id' => 'srrb_1',
            'changed_files' => ['app/Services/Ai/Example.php'],
            'merge_governance' => ['status' => 'merged', 'merge_commit' => 'abc1234'],
        ], ['session_id' => 'aess_1']);

        $this->assertSame(AutonomousLoopReceiptIntegrityService::STATE_MERGED, $receipt['lifecycle_state']);
        $this->assertTrue($receipt['completed']);
        $this->assertSame('abc1234', $receipt['merge_hash']);
        $this->assertTrue($receipt['inbox_post_merge']['present']);
        $this->assertSame('post_merge_result_receipt', $receipt['inbox_post_merge']['kind']);
        $this->assertSame(AutonomousLoopReceiptIntegrityService::INTEGRITY_OK, $receipt['integrity']);
    }

    public function test_planned_forge_cycle_is_not_completed(): void
    {
        $receipt = $this->service()->receiptFor([
            'cycle_id' => 'c1',
            'final_status' => 'cycle_completed_waiting_review_or_merge',
            'merge_performed' => false,
            'owner' => 'forge',
            'selected_finding' => ['finding_id' => 'f1', 'title' => 'Forge planned'],
            'inbox_item_id' => 'inbox_1',
            'result_bridge_id' => 'srrb_1',
            'blockers' => ['forge_runtime_dispatch_planned_only'],
        ], ['session_id' => 'aess_1']);

        $this->assertSame(AutonomousLoopReceiptIntegrityService::STATE_PLANNED, $receipt['lifecycle_state']);
        $this->assertFalse($receipt['completed']);
        $this->assertNotSame('', $receipt['replay_command']);
    }

    public function test_duplicate_commit_or_cycle_title_is_a_warning(): void
    {
        $session = [
            'session_id' => 'aess_dup',
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'cycles' => [
                [
                    'cycle_id' => 'c1',
                    'final_status' => 'cycle_completed_waiting_review_or_merge',
                    'selected_finding' => ['finding_id' => 'f1', 'title' => 'Harden router'],
                    'commit' => ['message' => 'Harden router'],
                    'inbox_item_id' => 'inbox_1',
                    'result_bridge_id' => 'srrb_1',
                ],
                [
                    'cycle_id' => 'c2',
                    'final_status' => 'cycle_completed_waiting_review_or_merge',
                    'selected_finding' => ['finding_id' => 'f1', 'title' => 'Harden router'],
                    'commit' => ['message' => 'Harden router'],
                    'inbox_item_id' => 'inbox_2',
                    'result_bridge_id' => 'srrb_2',
                ],
            ],
        ];

        $normalized = $this->service()->normalizeSession($session);

        $this->assertSame([], $normalized['cycles'][0]['loop_receipt']['warnings']);
        $this->assertContains('duplicate_commit_or_cycle_title', $normalized['cycles'][1]['loop_receipt']['warnings']);
        $this->assertSame(1, $normalized['loop_receipt_integrity']['cycles_with_warnings']);
    }

    // --- Integration with the AP-786 session path -------------------------

    public function test_completed_cycle_without_pre_merge_inbox_blocks_merge(): void
    {
        $finding = $this->finding('afdf_no_inbox');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn(['findings' => [$finding], 'status' => 'ready']);
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn(['top_candidate' => ['candidate_id' => 'afdf_no_inbox']]);
        });
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock): void {
            $mock->shouldReceive('materialize')->once()->andReturn($this->materializedSandbox());
        });
        $this->mock(AtlasForgeProviderInvocationDriverRouter::class)->shouldNotReceive('driverInvoke');
        // Owner flow says the result is merge-allowed/completed...
        $this->mock(Ap786OwnerFlowRunner::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturn([
                'status' => Ap786OwnerFlowExecutor::STATUS_COMPLETED,
                'uses_full_owner_runtime_chain' => true,
                'provider_router_used' => false,
                'merge_allowed' => true,
                'execution_result' => ['result_status' => 'completed', 'changed_files' => ['app/X.php']],
            ]);
        });
        // ...but the result bridge emitted NO inbox / evidence.
        $this->mock(StewardshipRuntimeResultProjector::class, function ($mock): void {
            $mock->shouldReceive('project')->once()->andReturn(['result_bridge_id' => '', 'inbox_item_id' => null]);
        });
        // Therefore the merge governor must never be reached.
        $this->mock(StewardshipBranchMergeGovernor::class)->shouldNotReceive('evaluate');

        $payload = app(AutonomousEvolutionSessionService::class)->run([
            'execute' => true,
            'repo_root' => $this->tmp,
            'cycles' => 1,
        ]);

        $cycle = $payload['cycles'][0];
        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertContains(AutonomousLoopReceiptIntegrityService::PRE_MERGE_INBOX_REQUIRED, $cycle['blockers']);
        $this->assertTrue($cycle['merge_skipped']);
        $this->assertSame(AutonomousLoopReceiptIntegrityService::STATE_BLOCKED, $cycle['loop_receipt']['lifecycle_state']);
    }

    public function test_session_attaches_loop_receipt_to_every_cycle(): void
    {
        $finding = $this->finding('afdf_dry');

        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock) use ($finding): void {
            $mock->shouldReceive('scan')->once()->andReturn(['findings' => [$finding], 'status' => 'ready']);
        });
        $this->mock(StewardshipPriorityRanker::class, function ($mock): void {
            $mock->shouldReceive('rank')->once()->andReturn(['top_candidate' => ['candidate_id' => 'afdf_dry']]);
        });

        // Dry-run (no execute): cycle is planned-only and must still carry a receipt.
        $payload = app(AutonomousEvolutionSessionService::class)->run(['cycles' => 1]);

        $receipt = $payload['cycles'][0]['loop_receipt'];
        $this->assertSame(AutonomousLoopReceiptIntegrityService::RECEIPT_SCHEMA, $receipt['schema_version']);
        $this->assertSame(AutonomousLoopReceiptIntegrityService::STATE_SKIPPED, $receipt['lifecycle_state']);
        $this->assertNotSame('', $receipt['replay_command']);
        $this->assertNotSame('', $receipt['next_action']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function finding(string $id, array $overrides = []): array
    {
        return array_merge([
            'finding_id' => $id,
            'finding_hash' => 'sha256:'.$id,
            'title' => 'Finding '.$id,
            'detail' => 'detail',
            'why_it_matters' => 'Throughput matters.',
            'kind' => 'bug',
            'severity' => 'high',
            'owner_candidate' => 'atlas_dev',
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php'],
            'affected_docs' => [],
            'evidence_refs' => ['expected_test:AutonomousEvolutionSessionServiceTest.php'],
            'origin_type' => 'runtime_gap',
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function materializedSandbox(): array
    {
        return [
            'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED,
            'sandbox_id' => 'afsb_test',
            'materialization' => [
                'worktree_path' => $this->tmp.'/worktree',
                'branch_name' => 'atlas/area-focus/agentic_engineering_os/atlas_dev/test',
            ],
        ];
    }
}
