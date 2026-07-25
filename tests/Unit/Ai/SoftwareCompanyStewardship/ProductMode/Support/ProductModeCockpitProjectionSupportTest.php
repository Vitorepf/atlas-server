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
 *
 * Residual pure sections/defaults/id resolvers/withoutGeneratedAt live on Support;
 * host keeps project() orchestration + finalize() clock only.
 */
final class ProductModeCockpitProjectionSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/Support/ProductModeCockpitProjectionSupport.php';

    private const HOST_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php';

    /** @var list<string> */
    private const PEELED = [
        'reviewQueue',
        'counters',
        'overallHealth',
        'nextActions',
        'claimPolicy',
        'withoutGeneratedAt',
        'areaId',
        'portfolioId',
        'areaFocusSection',
        'executiveSection',
        'newAreaSection',
        'selfExpandingSection',
        'outcomeHistorySection',
        'domainRuntimeCreationHandoffSection',
        'areaStewardshipActiveHandoffSection',
        'areaStewardshipActiveOperationSection',
        'continuousStewardshipLoopSection',
        'continuousStewardshipSchedulerSection',
        'defaultDevForgeRelease',
        'devForgeReleaseSection',
        'defaultOwnerSandboxRuntimeRunner',
        'ownerSandboxRuntimeRunnerSection',
        'defaultOwnerRuntimeResultBridge',
        'ownerRuntimeResultBridgeSection',
        'executiveAllocationHandoffSection',
        'productModeOperationalControlsSection',
        'loop24hObservabilitySection',
    ];

    public function test_support_peel_path_and_static_surface(): void
    {
        // tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/Support → repo root = 6 levels
        $abs = dirname(__DIR__, 6).'/'.self::SUPPORT_PATH;
        $this->assertFileExists($abs, 'Support peel must live at '.self::SUPPORT_PATH);

        $ref = new ReflectionClass(Support::class);
        foreach (self::PEELED as $method) {
            $this->assertTrue($ref->hasMethod($method), $method);
            $m = $ref->getMethod($method);
            $this->assertTrue($m->isPublic(), $method.' public');
            $this->assertTrue($m->isStatic(), $method.' static');
        }
    }

    public function test_explicit_path_proof_host_imports_support_and_no_longer_declares_peeled_helpers(): void
    {
        $root = dirname(__DIR__, 6);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs);
        $this->assertFileExists($hostAbs);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\Support\ProductModeCockpitProjectionSupport;',
            $hostSrc,
        );

        foreach ([
            'ProductModeCockpitProjectionSupport::areaId',
            'ProductModeCockpitProjectionSupport::portfolioId',
            'ProductModeCockpitProjectionSupport::areaFocusSection',
            'ProductModeCockpitProjectionSupport::executiveSection',
            'ProductModeCockpitProjectionSupport::loop24hObservabilitySection',
            'ProductModeCockpitProjectionSupport::defaultDevForgeRelease',
            'ProductModeCockpitProjectionSupport::defaultOwnerSandboxRuntimeRunner',
            'ProductModeCockpitProjectionSupport::defaultOwnerRuntimeResultBridge',
            'ProductModeCockpitProjectionSupport::withoutGeneratedAt',
            'ProductModeCockpitProjectionSupport::reviewQueue',
            'ProductModeCockpitProjectionSupport::counters',
            'ProductModeCockpitProjectionSupport::overallHealth',
            'ProductModeCockpitProjectionSupport::nextActions',
            'ProductModeCockpitProjectionSupport::claimPolicy',
        ] as $needle) {
            $this->assertStringContainsString($needle, $hostSrc, "Host must call {$needle}");
        }

        // Clock residual stays on host finalize only.
        $this->assertStringContainsString('function finalize', $hostSrc);
        $this->assertStringContainsString('DateTimeImmutable', $hostSrc);

        foreach (self::PEELED as $method) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bfunction\s+'.$method.'\s*\(/',
                $hostSrc,
                "Host must not declare peeled pure helper {$method}",
            );
        }
    }

    public function test_area_and_portfolio_id_defaults_and_trim(): void
    {
        $this->assertSame('agentic_engineering_os', Support::areaId([]));
        $this->assertSame('custom_area', Support::areaId(['area_id' => '  custom_area  ']));
        $this->assertSame('from_area_alias', Support::areaId(['area' => 'from_area_alias']));

        $this->assertSame(
            'atlas_software_company',
            Support::portfolioId('', []),
        );
        $this->assertSame(
            'pf_from_input',
            Support::portfolioId('ignored', ['portfolio_id' => ' pf_from_input ']),
        );
        $this->assertSame(
            'pf_arg',
            Support::portfolioId('pf_arg', []),
        );
    }

    public function test_without_generated_at_strips_nested_generated_at(): void
    {
        $clean = Support::withoutGeneratedAt([
            'generated_at' => '2026-01-01T00:00:00+00:00',
            'status' => 'ready',
            'nested' => [
                'generated_at' => 'x',
                'ok' => true,
            ],
        ]);

        $this->assertSame([
            'status' => 'ready',
            'nested' => ['ok' => true],
        ], $clean);
    }

    public function test_section_projectors_preserve_provider_safe_shape(): void
    {
        $areaFocus = Support::areaFocusSection([
            'schema_version' => 'af.v1',
            'status' => 'ready',
            'area_summary' => ['name' => 'core'],
            'health' => ['overall' => 'healthy'],
            'findings' => [['id' => 1]],
            'inbox_items' => [['id' => 'i1'], 'skip'],
            'work_orders' => [['id' => 'w1']],
            'budgets' => ['cap' => 1],
            'kill_switch_state' => ['armed' => false],
            'next_actions' => ['act', 12],
            'surface_hash' => 'h1',
            'secret_raw' => 'must_not_leak',
        ]);

        $this->assertSame('af.v1', $areaFocus['schema_version']);
        $this->assertSame('ready', $areaFocus['status']);
        $this->assertSame([['id' => 'i1']], $areaFocus['inbox_items']);
        $this->assertSame(['act'], $areaFocus['next_actions']);
        $this->assertArrayNotHasKey('secret_raw', $areaFocus);

        $loop24 = Support::loop24hObservabilitySection([
            'schema_version' => 'obs.v1',
            'ap_contract' => 'AP-790',
            'area_id' => 'a1',
            'metrics' => ['blocked_by_reason' => ['x' => 1]],
            'active_worktrees' => [['path' => 'wt'], 'nope'],
            'quarantined_count' => 2,
            'observability_hash' => 'oh',
        ]);
        $this->assertTrue($loop24['read_only']);
        $this->assertSame('AP-790', $loop24['ap_contract']);
        $this->assertSame([['path' => 'wt']], $loop24['active_worktrees']);
        $this->assertSame(2, $loop24['quarantined_count']);
        $this->assertSame(['x' => 1], $loop24['blocked_by_reason']);
    }

    public function test_default_placeholders_are_not_requested_and_claim_safe(): void
    {
        $release = Support::defaultDevForgeRelease('area_x');
        $this->assertSame('not_requested', $release['status']);
        $this->assertSame('AP-747', $release['ap_contract']);
        $this->assertSame('area_x', $release['area_id']);
        $this->assertFalse($release['claim_policy']['provider_invoked']);

        $sandbox = Support::defaultOwnerSandboxRuntimeRunner('area_y');
        $this->assertSame('not_requested', $sandbox['status']);
        $this->assertSame('AP-759', $sandbox['ap_contract']);
        $this->assertFalse($sandbox['claim_policy']['owner_runtime_command_executed']);

        $bridge = Support::defaultOwnerRuntimeResultBridge('area_z');
        $this->assertSame('not_reported', $bridge['status']);
        $this->assertSame('AP-750', $bridge['ap_contract']);
        $this->assertFalse($bridge['claim_policy']['owner_runtime_invoked_by_bridge']);
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
