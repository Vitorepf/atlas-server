<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerQueueConsumptionGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerQueueReleaseGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerRuntimeExecutionAdapter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerRuntimeResultProjector;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerSandboxRuntimeRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\StewardshipOutcomeProjector;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use Tests\TestCase;

/**
 * Proves the AP-786 owner-flow executor composes the REAL owner chain in order
 * (AP-747 -> AP-748 -> AP-749 -> AP-758 -> AP-759 -> AP-750), threads the owner
 * result into AP-750, blocks before the result bridge when AP-759 fails, and
 * never claims a fake Forge dispatch. It uses interface spies — the executor
 * never touches a provider driver router.
 */
final class Ap786OwnerFlowExecutorTest extends TestCase
{
    /** @var object{log:list<string>,captured:array<string,array<string,mixed>>} */
    private object $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new class
        {
            /** @var list<string> */
            public array $log = [];

            /** @var array<string,array<string,mixed>> */
            public array $captured = [];

            /** @param array<string,mixed> $input */
            public function rec(string $ap, array $input): void
            {
                $this->log[] = $ap;
                $this->captured[$ap] = $input;
            }
        };
    }

    public function test_executes_owner_chain_in_order_and_bridges_owner_result(): void
    {
        $ownerResult = $this->ownerResult('completed');
        $executor = $this->executor(['runner' => $this->runnerReport($ownerResult)]);

        $report = $executor->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertTrue($report['uses_full_owner_runtime_chain']);
        $this->assertFalse($report['provider_router_used']);
        $this->assertTrue($report['merge_allowed']);

        // Owners are invoked in the canonical order.
        $this->assertSame(['AP-747', 'AP-748', 'AP-749', 'AP-758', 'AP-759', 'AP-750'], $this->recorder->log);

        // AP-750 received exactly the AP-759 owner_result and the AP-749 consumption.
        $this->assertSame($ownerResult['result_id'], $this->recorder->captured['AP-750']['owner_result']['result_id']);
        $this->assertSame('afcons_x', (string) data_get($this->recorder->captured['AP-750'], 'consumption_report.consumption_id'));
        $this->assertNotSame('', (string) $report['result_bridge_id']);
        $this->assertSame($ownerResult, $report['owner_result']);
    }

    public function test_blocks_before_result_bridge_when_ap759_blocks(): void
    {
        $executor = $this->executor(['runner' => ['status' => StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED, 'blockers' => ['runtime_command_not_allowed']]]);

        $report = $executor->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_BLOCKED, $report['status']);
        $this->assertSame('ap759_owner_command_failed', $report['reason']);
        $this->assertFalse($report['merge_allowed']);
        // The result bridge (AP-750) is never reached when AP-759 fails.
        $this->assertNotContains('AP-750', $this->recorder->log);
        $this->assertSame(['AP-747', 'AP-748', 'AP-749', 'AP-758', 'AP-759'], $this->recorder->log);
    }

    public function test_forge_blocks_honestly_without_faking_dispatch(): void
    {
        $executor = $this->executor();

        $report = $executor->execute($this->input(['owner' => 'forge']));

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_BLOCKED, $report['status']);
        $this->assertSame('forge_obra_dispatch_required', $report['reason']);
        $this->assertFalse($report['provider_router_used']);
        // No owner step is invoked — forge is not faked.
        $this->assertSame([], $this->recorder->log);
    }

    public function test_owner_result_not_completed_still_bridges_but_blocks_merge(): void
    {
        $ownerResult = $this->ownerResult('failed');
        $executor = $this->executor(['runner' => $this->runnerReport($ownerResult)]);

        $report = $executor->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_RESULT_FAILED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        // AP-750 still records the failed result for evidence/inbox.
        $this->assertContains('AP-750', $this->recorder->log);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function executor(array $overrides = []): Ap786OwnerFlowExecutor
    {
        $release = $overrides['release'] ?? [
            'status' => AreaFocusDevForgeReleaseService::STATUS_READY,
            'release_id' => 'afrel_x',
            'queue_item' => ['queue_item_id' => 'afq_x'],
        ];
        $outcome = $overrides['outcome'] ?? ['status' => StewardshipOutcomeEvidenceBridgeService::STATUS_READY];
        $consumption = $overrides['consumption'] ?? [
            'status' => AreaFocusOwnerQueueConsumptionGateService::STATUS_READY,
            'consumption_id' => 'afcons_x',
            'release_id' => 'afrel_x',
            'queue_item_id' => 'afq_x',
            'sandbox_binding' => ['sandbox_id' => 'afsb_x', 'branch_name' => 'atlas/area-focus/x'],
        ];
        $adapter = $overrides['adapter'] ?? [
            'status' => StewardshipOwnerRuntimeExecutionAdapterService::STATUS_READY,
            'owner_execution_id' => 'afexec_x',
        ];
        $runner = $overrides['runner'] ?? $this->runnerReport($this->ownerResult('completed'));
        $bridge = $overrides['bridge'] ?? [
            'status' => StewardshipOwnerRuntimeResultBridgeService::STATUS_READY,
            'result_bridge_id' => 'afobr_x',
        ];

        return new Ap786OwnerFlowExecutor(
            new class($this->recorder, $release) implements OwnerQueueReleaseGate {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function release(array $input): array
                {
                    $this->rec->rec('AP-747', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $outcome) implements StewardshipOutcomeProjector {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input = []): array
                {
                    $this->rec->rec('AP-748', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $consumption) implements OwnerQueueConsumptionGate {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input): array
                {
                    $this->rec->rec('AP-749', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $adapter) implements OwnerRuntimeExecutionAdapter {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input): array
                {
                    $this->rec->rec('AP-758', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $runner) implements OwnerSandboxRuntimeRunner {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input): array
                {
                    $this->rec->rec('AP-759', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $bridge) implements OwnerRuntimeResultProjector {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input): array
                {
                    $this->rec->rec('AP-750', $input);

                    return $this->report;
                }
            },
        );
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @return array<string,mixed>
     */
    private function runnerReport(array $ownerResult): array
    {
        return [
            'status' => StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY,
            'owner_sandbox_run_id' => 'afrun_x',
            'owner_result' => $ownerResult,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ownerResult(string $status): array
    {
        return [
            'result_id' => 'afrunres_x',
            'consumption_id' => 'afcons_x',
            'release_id' => 'afrel_x',
            'queue_item_id' => 'afq_x',
            'target_owner' => 'atlas_dev',
            'result_status' => $status,
            'summary' => 'Atlas Dev senior loop ran inside the AP-756 worktree.',
            'changed_files' => ['app/Services/Ai/Example.php'],
            'tests' => ['php artisan test --filter=Example'],
            'evidence_pack' => ['summary' => 'AP-759 owner runtime command receipt.'],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'area_id' => 'agentic_engineering_os',
            'portfolio_id' => 'atlas_software_company',
            'owner' => 'atlas_dev',
            'actor' => 'operator',
            'finding' => [
                'finding_id' => 'aff_x',
                'title' => 'Improve owner flow',
                'proposed_next_action' => 'Implement the smallest correct fix.',
            ],
            'allowed_files' => ['app/Services/Ai/Example.php'],
            'preflight_report' => ['handoff_packet' => ['handoff_hash' => 'sha256:handoff_x']],
            'sandbox_record' => ['sandbox_id' => 'afsb_x', 'status' => 'materialized'],
            'worktree_path' => '/tmp/atlas-ap786-worktree',
            'execute' => true,
        ], $overrides);
    }
}
