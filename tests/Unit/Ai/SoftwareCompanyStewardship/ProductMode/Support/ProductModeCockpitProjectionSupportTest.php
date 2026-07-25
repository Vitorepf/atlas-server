<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ProductMode\Support;

use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveAllocationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipLoopService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipRecurringSchedulerService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlsReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\Support\ProductModeCockpitProjectionSupport as Support;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure Support peel for Product Mode cockpit projection — no I/O, no service, no DB.
 */
final class ProductModeCockpitProjectionSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/Support/ProductModeCockpitProjectionSupport.php';

    public function test_support_peel_path_and_static_surface(): void
    {
        // tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/Support → repo root = 6 levels
        $abs = dirname(__DIR__, 6).'/'.self::SUPPORT_PATH;
        $this->assertFileExists($abs, 'Support peel must live at '.self::SUPPORT_PATH);

        $ref = new ReflectionClass(Support::class);
        foreach (['reviewQueue', 'counters', 'overallHealth', 'nextActions', 'claimPolicy'] as $method) {
            $this->assertTrue($ref->hasMethod($method), $method);
            $m = $ref->getMethod($method);
            $this->assertTrue($m->isPublic());
            $this->assertTrue($m->isStatic());
        }
    }

    public function test_claim_policy_is_read_only_surface_only(): void
    {
        $policy = Support::claimPolicy();

        $this->assertTrue($policy['read_only_over_repo']);
        $this->assertTrue($policy['surface_only']);
        $this->assertFalse($policy['writes_local_state']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['dev_invoked']);
        $this->assertFalse($policy['forge_invoked']);
        $this->assertFalse($policy['branch_created']);
        $this->assertFalse($policy['domain_runtime_created']);
        $this->assertFalse($policy['merge_without_operator']);
        $this->assertFalse($policy['deploy_without_operator']);
        $this->assertFalse($policy['autoimplementation_allowed']);
        $this->assertTrue($policy['operator_review_required']);
    }

    public function test_next_actions_always_ends_with_read_only_reminder(): void
    {
        $actions = Support::nextActions([]);

        $this->assertCount(1, $actions);
        $this->assertStringContainsString('Keep this cockpit read-only', $actions[0]);
    }

    public function test_next_actions_emits_priority_messages_from_counters(): void
    {
        $actions = Support::nextActions([
            'executive_pending_review' => 2,
            'new_area_blocked_review' => 1,
            'product_mode_control_blocked' => 1,
            'dev_forge_release_blocked' => 1,
        ]);

        $this->assertStringContainsString('AP-736', $actions[0]);
        $this->assertStringContainsString('AP-737', $actions[1]);
        $this->assertStringContainsString('AP-747', implode("\n", $actions));
        $this->assertStringContainsString('AP-754', implode("\n", $actions));
        $this->assertStringContainsString('Keep this cockpit read-only', end($actions));
    }

    public function test_overall_health_blocked_when_any_component_blocked(): void
    {
        $empty = $this->emptyComponents();
        $empty['executive']['status'] = ProductModeCockpitSurfaceService::STATUS_BLOCKED;

        $health = Support::overallHealth(
            [],
            $empty['executive'],
            $empty['newAreaGate'],
            $empty['selfExpanding'],
            $empty['outcomeHistory'],
            $empty['handoff'],
            $empty['areaActiveHandoff'],
            $empty['areaActiveOperation'],
            $empty['continuousLoop'],
            $empty['continuousScheduler'],
            $empty['devForgeRelease'],
            $empty['ownerSandboxRuntime'],
            $empty['ownerRuntimeResult'],
            $empty['executiveAllocationHandoff'],
            $empty['productModeControls'],
        );

        $this->assertSame(ProductModeCockpitSurfaceService::STATUS_BLOCKED, $health);
    }

    public function test_overall_health_review_when_pending_counters(): void
    {
        $empty = $this->emptyComponents();

        $health = Support::overallHealth(
            ['executive_pending_review' => 3],
            $empty['executive'],
            $empty['newAreaGate'],
            $empty['selfExpanding'],
            $empty['outcomeHistory'],
            $empty['handoff'],
            $empty['areaActiveHandoff'],
            $empty['areaActiveOperation'],
            $empty['continuousLoop'],
            $empty['continuousScheduler'],
            $empty['devForgeRelease'],
            $empty['ownerSandboxRuntime'],
            $empty['ownerRuntimeResult'],
            $empty['executiveAllocationHandoff'],
            $empty['productModeControls'],
        );

        $this->assertSame('review', $health);
    }

    public function test_overall_health_ready_when_clean(): void
    {
        $empty = $this->emptyComponents();

        $health = Support::overallHealth(
            [],
            $empty['executive'],
            $empty['newAreaGate'],
            $empty['selfExpanding'],
            $empty['outcomeHistory'],
            $empty['handoff'],
            $empty['areaActiveHandoff'],
            $empty['areaActiveOperation'],
            $empty['continuousLoop'],
            $empty['continuousScheduler'],
            $empty['devForgeRelease'],
            $empty['ownerSandboxRuntime'],
            $empty['ownerRuntimeResult'],
            $empty['executiveAllocationHandoff'],
            $empty['productModeControls'],
        );

        $this->assertSame(ProductModeCockpitSurfaceService::STATUS_READY, $health);
    }

    public function test_counters_maps_core_fields(): void
    {
        $empty = $this->emptyComponents();
        $areaFocus = [
            'findings' => ['total' => 4],
            'inbox_items' => [['id' => 'a'], ['id' => 'b']],
            'work_orders' => [['id' => 'w1']],
        ];
        $executive = ['item_count' => 5, 'decision_summary' => ['pending_operator_review' => 2]];
        $newAreaGate = ['gate_item_count' => 3, 'decision_summary' => ['blocked_awaiting_operator_review' => 1]];
        $selfExpanding = [
            'operator_inbox' => ['item_count' => 7],
            'expansion_summary' => ['ready_for_domain_runtime_creation_gate' => 1],
        ];
        $reviewQueue = [['id' => 'r1'], ['id' => 'r2']];

        $counters = Support::counters(
            $areaFocus,
            $executive,
            $newAreaGate,
            $selfExpanding,
            $empty['outcomeHistory'],
            $empty['handoff'],
            $empty['areaActiveHandoff'],
            $empty['areaActiveOperation'],
            $empty['continuousLoop'],
            $empty['continuousScheduler'],
            $empty['devForgeRelease'],
            $empty['ownerSandboxRuntime'],
            $empty['ownerRuntimeResult'],
            $empty['executiveAllocationHandoff'],
            $empty['productModeControls'],
            $reviewQueue,
        );

        $this->assertSame(4, $counters['area_findings']);
        $this->assertSame(2, $counters['area_inbox_items']);
        $this->assertSame(1, $counters['work_orders']);
        $this->assertSame(5, $counters['executive_items']);
        $this->assertSame(2, $counters['executive_pending_review']);
        $this->assertSame(3, $counters['new_area_gate_items']);
        $this->assertSame(1, $counters['new_area_blocked_review']);
        $this->assertSame(7, $counters['self_expanding_inbox_items']);
        $this->assertSame(2, $counters['review_queue_items']);
        $this->assertSame(1, $counters['ready_for_domain_runtime_creation_gate']);
    }

    public function test_counters_status_flags_for_active_operation_and_controls(): void
    {
        $empty = $this->emptyComponents();
        $areaActiveOperation = [
            'status' => AreaStewardshipActiveOperatingService::STATUS_PARTIAL,
            'counts' => [
                'work_orders' => 3,
                'spec_drafts' => 2,
                'ready_branch_handoffs' => 1,
            ],
        ];
        $productModeControls = [
            'status' => ProductModeOperationalControlsReadModelService::STATUS_REVIEW,
            'blockers' => ['missing_repo'],
            'branch_review_center' => ['pending_review_count' => 4],
            'evidence_inspector' => ['missing_refs' => ['e1', 'e2']],
        ];

        $counters = Support::counters(
            [],
            [],
            [],
            [],
            $empty['outcomeHistory'],
            $empty['handoff'],
            $empty['areaActiveHandoff'],
            $areaActiveOperation,
            $empty['continuousLoop'],
            $empty['continuousScheduler'],
            $empty['devForgeRelease'],
            $empty['ownerSandboxRuntime'],
            $empty['ownerRuntimeResult'],
            $empty['executiveAllocationHandoff'],
            $productModeControls,
            [],
        );

        $this->assertSame(1, $counters['area_active_operations']);
        $this->assertSame(0, $counters['ready_area_active_operations']);
        $this->assertSame(1, $counters['partial_area_active_operations']);
        $this->assertSame(3, $counters['area_active_operation_work_orders']);
        $this->assertSame(1, $counters['product_mode_control_review_required']);
        $this->assertSame(0, $counters['product_mode_control_blocked']);
        $this->assertSame(1, $counters['product_mode_control_blockers']);
        $this->assertSame(4, $counters['product_mode_pending_branch_reviews']);
        $this->assertSame(2, $counters['product_mode_missing_evidence_refs']);
    }

    public function test_review_queue_projects_executive_and_sorts_by_risk(): void
    {
        $empty = $this->emptyComponents();
        $executive = [
            'items' => [
                [
                    'inbox_item_id' => 'low-1',
                    'title' => 'Low risk item',
                    'status' => 'pending_operator_review',
                    'risk_level' => 'low',
                    'target_area' => 'a',
                    'priority_score' => 50,
                    'stable_decision_anchor' => ['k' => 'v'],
                ],
                [
                    'inbox_item_id' => 'high-1',
                    'title' => 'High risk item',
                    'status' => 'pending_operator_review',
                    'risk_level' => 'high',
                    'target_area' => 'b',
                    'priority_score' => 10,
                ],
            ],
        ];
        $productModeControls = [
            'status' => ProductModeOperationalControlsReadModelService::STATUS_BLOCKED,
            'controls_hash' => 'ctrl-hash',
            'area_id' => 'agentic_engineering_os',
            'blockers' => ['kill_switch_on'],
            'repo_onboarding' => ['repository' => 'atlas-server'],
            'autonomy_tiers' => ['current_tier' => 0],
            'safety_controls' => ['kill_switch_active' => true],
        ];

        $queue = Support::reviewQueue(
            $executive,
            $empty['newAreaGate'],
            $empty['selfExpanding'],
            $empty['outcomeHistory'],
            $empty['handoff'],
            $empty['areaActiveHandoff'],
            $empty['areaActiveOperation'],
            $empty['continuousLoop'],
            $empty['continuousScheduler'],
            $empty['devForgeRelease'],
            $empty['ownerSandboxRuntime'],
            $empty['ownerRuntimeResult'],
            $empty['executiveAllocationHandoff'],
            $productModeControls,
        );

        $this->assertGreaterThanOrEqual(3, count($queue));
        $this->assertSame('product_mode_operational_controls', $queue[0]['kind']);
        $this->assertSame('critical', $queue[0]['risk_level']);
        $this->assertSame('AP-754', $queue[0]['source_ap']);
        $this->assertSame(['kill_switch_on'], $queue[0]['blockers']);
        $this->assertFalse($queue[0]['irreversible_action_allowed']);
        $this->assertFalse($queue[0]['autoimplementation_allowed']);

        $kinds = array_column($queue, 'kind');
        $this->assertContains('autonomous_executive_recommendation', $kinds);

        // critical/high before low
        $risks = array_column($queue, 'risk_level');
        $this->assertSame('critical', $risks[0]);
        $this->assertContains('high', $risks);
        $this->assertContains('low', $risks);
        $this->assertLessThan(array_search('low', $risks, true), array_search('high', $risks, true));
    }

    public function test_review_queue_includes_owner_sandbox_and_allocation_handoff(): void
    {
        $empty = $this->emptyComponents();
        $ownerSandboxRuntime = [
            'status' => StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED,
            'owner_sandbox_run_id' => 'run-1',
            'area_id' => 'agentic_engineering_os',
            'target_owner' => 'dev',
            'blockers' => [],
            'command_plan' => [
                'run_id' => 'run-1',
                'command_hash' => 'ch',
                'command_display' => 'php artisan test',
                'requires_provider_authority' => false,
                'worktree_path_hash' => 'wh',
            ],
        ];
        $executiveAllocationHandoff = [
            'status' => AutonomousExecutiveAllocationHandoffService::STATUS_READY,
            'allocation_handoff_packets' => [
                [
                    'handoff_packet_id' => 'pkt-1',
                    'handoff_status' => 'ready',
                    'target_area' => 'agentic_engineering_os',
                    'packet_hash' => 'ph',
                    'source_pack_id' => 'pack',
                    'source_recommendation_id' => 'rec',
                    'source_decision_id' => 'dec',
                    'target_owner' => 'area',
                    'target_owner_contract' => 'AP-743',
                    'target_owner_doc' => 'docs/x.md',
                    'allowed_next_actions' => ['review'],
                    'blockers' => [],
                ],
            ],
        ];

        $queue = Support::reviewQueue(
            [],
            [],
            [],
            $empty['outcomeHistory'],
            $empty['handoff'],
            $empty['areaActiveHandoff'],
            $empty['areaActiveOperation'],
            $empty['continuousLoop'],
            $empty['continuousScheduler'],
            $empty['devForgeRelease'],
            $ownerSandboxRuntime,
            $empty['ownerRuntimeResult'],
            $executiveAllocationHandoff,
            $empty['productModeControls'],
        );

        $kinds = array_column($queue, 'kind');
        $this->assertContains('owner_sandbox_runtime_runner', $kinds);
        $this->assertContains('executive_allocation_handoff', $kinds);

        $sandbox = null;
        foreach ($queue as $item) {
            if ($item['kind'] === 'owner_sandbox_runtime_runner') {
                $sandbox = $item;
                break;
            }
        }
        $this->assertNotNull($sandbox);
        $this->assertSame('review_ap759_owner_runtime_command_plan_before_execute', $sandbox['recommended_operator_action']);
        $this->assertSame('AP-759', $sandbox['source_ap']);
    }

    public function test_review_queue_skips_not_requested_components(): void
    {
        $empty = $this->emptyComponents();

        $queue = Support::reviewQueue(
            [],
            [],
            [],
            $empty['outcomeHistory'],
            $empty['handoff'],
            $empty['areaActiveHandoff'],
            $empty['areaActiveOperation'],
            $empty['continuousLoop'],
            $empty['continuousScheduler'],
            $empty['devForgeRelease'],
            $empty['ownerSandboxRuntime'],
            $empty['ownerRuntimeResult'],
            $empty['executiveAllocationHandoff'],
            $empty['productModeControls'],
        );

        $this->assertSame([], $queue);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function emptyComponents(): array
    {
        return [
            'executive' => ['status' => 'ready', 'items' => []],
            'newAreaGate' => ['status' => 'ready', 'gate_items' => []],
            'selfExpanding' => ['status' => 'ready', 'operator_inbox' => ['items' => []]],
            'outcomeHistory' => ['status' => 'ready', 'morning_inbox_items' => [], 'release_outcome_summary' => ['release_count' => 0]],
            'handoff' => ['status' => 'ready', 'handoff_packets' => []],
            'areaActiveHandoff' => ['status' => 'idle', 'active_handoff_packets' => []],
            'areaActiveOperation' => ['status' => 'idle'],
            'continuousLoop' => ['status' => AtlasContinuousStewardshipLoopService::STATUS_PAUSED],
            'continuousScheduler' => ['status' => AtlasContinuousStewardshipRecurringSchedulerService::STATUS_PAUSED],
            'devForgeRelease' => ['status' => 'not_requested'],
            'ownerSandboxRuntime' => ['status' => 'not_requested'],
            'ownerRuntimeResult' => ['status' => 'not_requested'],
            'executiveAllocationHandoff' => ['status' => 'idle', 'allocation_handoff_packets' => []],
            'productModeControls' => ['status' => ProductModeOperationalControlsReadModelService::STATUS_READY, 'blockers' => []],
        ];
    }
}
