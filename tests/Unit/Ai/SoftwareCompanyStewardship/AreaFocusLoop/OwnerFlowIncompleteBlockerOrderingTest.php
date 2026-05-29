<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Loop-watch regression (2026-05-29).
 *
 * A real reliable-24h run blocked finding slice_22e7e67e3eae2512 twice with
 * `pre_merge_inbox_required`. Root cause: the owner runtime returned
 * no_patch_needed WITHOUT provider proof (providerCalls=0, no changed files),
 * which makes AP-765 emit no result_bridge_id. The pre-merge gate ran BEFORE
 * the owner-flow completion check, so it masked the precise machine blocker
 * (`owner_runtime_no_patch_needed_without_proof`) behind the misleading
 * `pre_merge_inbox_required`, AND bypassed governCycleOutcome — leaving the
 * blocked cycle ungoverned (no repair_policy) and re-selectable.
 *
 * These invariants pin the corrected ordering: a non-completed owner runtime is
 * classified with its true blocker and routed through cycle governance; the
 * pre-merge inbox gate only acts as a backstop for genuinely completed flows.
 */
final class OwnerFlowIncompleteBlockerOrderingTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $ownerFlowResult
     * @param  array<string,mixed>  $bridgeResult
     */
    private function runCycleWith(array $ownerFlowResult, array $bridgeResult): array
    {
        $this->app->instance(Ap786OwnerFlowRunner::class, new class($ownerFlowResult) implements Ap786OwnerFlowRunner
        {
            /** @param array<string,mixed> $result */
            public function __construct(private readonly array $result) {}

            public function execute(array $input): array
            {
                return $this->result;
            }
        });

        $this->app->instance(StewardshipRuntimeResultProjector::class, new class($bridgeResult) implements StewardshipRuntimeResultProjector
        {
            /** @param array<string,mixed> $result */
            public function __construct(private readonly array $result) {}

            public function project(array $input): array
            {
                return $this->result;
            }
        });

        $service = $this->app->make(AutonomousEvolutionSessionService::class);

        $input = [
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'repo_root' => base_path(),
            'actor' => 'operator',
            'validation_commands' => [],
            'continue_on_blocked' => true,
            'provider' => 'minimax',
            'model' => 'MiniMax-M2',
        ];
        $finding = [
            'finding_id' => 'slice_test_no_patch',
            'finding_hash' => 'sha256:slice_test_no_patch',
            'title' => 'Expose a bounded factory contract step',
            'detail' => 'STEP 1 of 3 — execute ONLY this step.',
            'owner_candidate' => 'atlas_dev',
        ];
        $selection = [
            'priority_report' => ['scope_profile' => 'factory_max'],
            'selection_rejections' => [],
        ];

        $method = new ReflectionMethod($service, 'runOwnerFlowCycle');
        $method->setAccessible(true);

        return $method->invoke(
            $service,
            'cyc_test',
            5,
            $input,
            $finding,
            $selection,
            'factory_max',
            'atlas_dev',
            ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'App\\Foo',
            ['status' => 'ready'],
            ['sandbox_id' => 'afsb_test'],
            base_path(),
            'atlas/area-focus/agentic_engineering_os/atlas_dev/testbranch',
            ['status' => 'ready'],
            ['status' => 'ready'],
        );
    }

    public function test_no_patch_without_proof_surfaces_true_blocker_and_is_governed_not_pre_merge_masked(): void
    {
        $cycle = $this->runCycleWith(
            ownerFlowResult: [
                'status' => 'completed',          // NOT STATUS_BLOCKED — must reach the merge_allowed check
                'merge_allowed' => false,         // but the runtime did not produce mergeable evidence
                'blockers' => ['owner_runtime_no_patch_needed_without_proof'],
                'execution_result' => ['result_status' => 'completed', 'summary' => 'no patch needed'],
            ],
            // AP-765 produced no bridge id (evidence-poor result) — the exact
            // condition that used to trip the pre-merge gate first.
            bridgeResult: ['result_bridge_id' => '', 'inbox_item_id' => null],
        );

        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertFalse($cycle['merge_performed']);

        $blockers = (array) ($cycle['blockers'] ?? []);
        // The precise machine blocker survives...
        $this->assertContains('owner_runtime_no_patch_needed_without_proof', $blockers);
        // ...and is NOT masked by the misleading pre-merge symptom.
        $this->assertNotContains('pre_merge_inbox_required', $blockers);

        // Routed through governCycleOutcome (governed, not an ungoverned early return).
        $this->assertArrayHasKey('repair_policy', $cycle, 'blocked owner-flow cycle must be governed');
    }

    public function test_completed_owner_flow_without_audit_trail_still_blocked_by_pre_merge_backstop(): void
    {
        // Genuinely completed owner flow, but (regression scenario) no audit
        // trail at all — the AP-791 backstop must still block the merge.
        $cycle = $this->runCycleWith(
            ownerFlowResult: [
                'status' => 'completed',
                'merge_allowed' => true,
                'blockers' => [],
                'execution_result' => ['result_status' => 'completed', 'summary' => 'done'],
            ],
            bridgeResult: ['result_bridge_id' => '', 'inbox_item_id' => null],
        );

        $this->assertSame('blocked', $cycle['final_status']);
        $this->assertFalse($cycle['merge_performed']);
        $this->assertContains('pre_merge_inbox_required', (array) ($cycle['blockers'] ?? []));
        // Backstop is also governed now (no ungoverned early return).
        $this->assertArrayHasKey('repair_policy', $cycle);
    }
}
