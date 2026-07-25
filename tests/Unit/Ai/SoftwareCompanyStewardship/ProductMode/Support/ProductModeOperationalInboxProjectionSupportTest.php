<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ProductMode\Support;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FirstFullCycleOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSystemCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayReadinessService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayStartService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\Support\ProductModeOperationalInboxProjectionSupport as Support;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure Support peel for Product Mode operational inbox projection — no I/O, no service, no DB.
 */
final class ProductModeOperationalInboxProjectionSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/Support/ProductModeOperationalInboxProjectionSupport.php';

    public function test_support_peel_path_and_static_surface(): void
    {
        // tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/Support → repo root = 6 levels
        $abs = dirname(__DIR__, 6).'/'.self::SUPPORT_PATH;
        $this->assertFileExists($abs, 'Support peel must live at '.self::SUPPORT_PATH);

        $ref = new ReflectionClass(Support::class);
        foreach ([
            'itemsFromReadiness',
            'itemsFromRunner',
            'itemsFromBranchCert',
            'itemsFromControls',
            'itemsFromFirstCycles',
            'itemsFromRuntimeEvents',
            'itemsFromAutonomousEvolution',
            'autonomousCycleItem',
            'ownerFlowStages',
            'cycleValidation',
            'rollbackInstruction',
            'cycleNextOperatorAction',
            'shortHash',
            'counters',
            'latestReceipts',
            'collectBlockers',
            'collectNextActions',
            'emptyState',
            'item',
            'dedupeItems',
            'claimPolicy',
            'hashIdentity',
            'slug',
        ] as $method) {
            $this->assertTrue($ref->hasMethod($method), $method);
            $m = $ref->getMethod($method);
            $this->assertTrue($m->isPublic());
            $this->assertTrue($m->isStatic());
        }
    }

    public function test_claim_policy_is_read_only_surface_only(): void
    {
        $policy = Support::claimPolicy();

        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['writes_repo']);
        $this->assertFalse($policy['invokes_provider']);
        $this->assertFalse($policy['invokes_dev']);
        $this->assertFalse($policy['invokes_forge']);
        $this->assertFalse($policy['installs_scheduler']);
        $this->assertFalse($policy['starts_loop']);
        $this->assertFalse($policy['fabricates_receipts']);
    }

    public function test_items_from_readiness_blocked_emits_alert_and_kill_switch(): void
    {
        $items = Support::itemsFromReadiness('aeos', 'portfolio', [
            'status' => ContinuousStewardshipDayReadinessService::STATUS_BLOCKED,
            'blockers' => ['global_kill_switch_active', 'rate_budget_exceeded'],
        ]);

        $kinds = array_column($items, 'kind');
        $this->assertContains('continuous_24h_readiness_blocked', $kinds);
        $this->assertContains('kill_switch_active', $kinds);
        $this->assertContains('rate_limited_or_budget_blocked', $kinds);
        $this->assertSame('alert', $items[0]['bucket']);
        $this->assertSame('blocked', $items[0]['cycle_state']);
        $this->assertSame(Support::ITEM_SCHEMA, $items[0]['schema_version']);
    }

    public function test_items_from_readiness_ready_emits_recommendation(): void
    {
        $items = Support::itemsFromReadiness('aeos', 'portfolio', [
            'status' => ContinuousStewardshipDayReadinessService::STATUS_READY,
            'operator_start_command' => 'atlas stewardship day-start',
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('continuous_24h_readiness_ready', $items[0]['kind']);
        $this->assertSame('recommendation', $items[0]['bucket']);
        $this->assertSame('ready', $items[0]['cycle_state']);
        $this->assertSame('atlas stewardship day-start', $items[0]['payload']['operator_start_command']);
    }

    public function test_items_from_runner_kill_switch_budget_and_tick(): void
    {
        $kill = Support::itemsFromRunner('aeos', 'p', [
            'kill_switch_status' => ['global_active' => true, 'detail' => 'operator hold'],
            'status' => ContinuousStewardshipRunnerService::STATUS_BLOCKED,
        ]);
        $this->assertSame('kill_switch_active', $kill[0]['kind']);

        $budget = Support::itemsFromRunner('aeos', 'p', [
            'status' => ContinuousStewardshipRunnerService::STATUS_BUDGET_EXHAUSTED,
            'budget_status' => ['status' => 'exhausted'],
        ]);
        $this->assertSame('rate_limited', $budget[0]['kind']);
        $this->assertSame('rate_limited', $budget[0]['cycle_state']);

        $tick = Support::itemsFromRunner('aeos', 'p', [
            'last_run' => [
                'tick_admitted' => true,
                'status' => 'ran',
                'tick_status' => 'completed',
                'runner_run_id' => 'run-1',
            ],
        ]);
        $this->assertSame('continuous_runner_tick_executed', $tick[0]['kind']);
        $this->assertSame('insight', $tick[0]['bucket']);
    }

    public function test_items_from_branch_cert_skips_when_certified(): void
    {
        $this->assertSame([], Support::itemsFromBranchCert('a', 'p', [
            'status' => StewardshipBranchSystemCertificationService::STATUS_CERTIFIED,
        ]));

        $items = Support::itemsFromBranchCert('a', 'p', [
            'status' => StewardshipBranchSystemCertificationService::STATUS_BLOCKED,
            'blockers' => ['missing_signed_off'],
        ]);
        $this->assertCount(1, $items);
        $this->assertSame('branch_review_required', $items[0]['kind']);
        $this->assertStringContainsString('missing_signed_off', $items[0]['summary']);
    }

    public function test_items_from_controls_safety_and_pending_review(): void
    {
        $items = Support::itemsFromControls('a', 'p', [
            'safety_controls' => [
                'kill_switch_active' => true,
                'rate_limited' => true,
            ],
            'branch_review_center' => [
                'pending_review_count' => 2,
            ],
        ]);

        $kinds = array_column($items, 'kind');
        $this->assertContains('product_mode_kill_switch_active', $kinds);
        $this->assertContains('product_mode_rate_limited', $kinds);
        $this->assertContains('branch_review_required', $kinds);
        $this->assertStringContainsString('2 branch', $items[2]['summary']);
    }

    public function test_items_from_first_cycles_with_deferred_dev_forge_stage(): void
    {
        $items = Support::itemsFromFirstCycles('a', 'p', [
            'cycles' => [
                [
                    'cycle_id' => 'c1',
                    'final_status' => FirstFullCycleOrchestratorService::STATUS_CYCLE_CLOSED,
                ],
            ],
        ], [
            'stages' => [
                'dev_forge_execution' => [
                    'status' => FirstFullCycleOrchestratorService::STAGE_DEFERRED,
                    'ap_contract' => 'AP-767',
                    'detail' => 'waiting for operator execution_result',
                ],
                'other' => [
                    'status' => 'passed',
                    'ap_contract' => 'AP-768',
                ],
            ],
        ]);

        $kinds = array_column($items, 'kind');
        $this->assertContains('first_full_cycle_executed', $kinds);
        $this->assertContains('dev_forge_deferred', $kinds);
        $this->assertSame('deferred', $items[1]['cycle_state']);
    }

    public function test_items_from_runtime_events_takes_latest_five_reversed(): void
    {
        $events = [];
        for ($i = 1; $i <= 7; $i++) {
            $events[] = [
                'event_id' => 'e'.$i,
                'owner' => 'owner-'.$i,
                'result_status' => 'ok',
            ];
        }

        $items = Support::itemsFromRuntimeEvents('a', 'p', ['events' => $events]);
        $this->assertCount(5, $items);
        // reverse + slice(0,5) → e7..e3
        $this->assertSame('e7', $items[0]['payload']['event']['event_id']);
        $this->assertSame('e3', $items[4]['payload']['event']['event_id']);
    }

    public function test_autonomous_cycle_item_classifies_fake_block_merge_and_review(): void
    {
        $this->assertNull(Support::autonomousCycleItem('a', 'p', 's1', [
            'final_status' => 'dry_run_planned',
        ], false, true));

        $fake = Support::autonomousCycleItem('a', 'p', 's1', [
            'cycle_id' => 'c-fake',
            'final_status' => 'blocked',
            'blockers' => ['full_atlas_forge_flow_required'],
            'selected_finding' => ['title' => 'x'],
            'branch_ref' => 'feat/x',
            'sandbox_id' => 'sbx',
        ], false, true);
        $this->assertSame('autonomous_cycle_blocked_fake_flow', $fake['kind']);
        $this->assertSame('alert', $fake['bucket']);
        $this->assertStringContainsString('Do NOT use --allow-direct-provider-driver', $fake['payload']['ap786_cycle']['next_operator_action']);
        $this->assertFalse($fake['payload']['ap786_cycle']['anti_fake_proof']['full_owner_flow']);
        $this->assertCount(7, $fake['payload']['ap786_cycle']['owner_flow_stages']);

        $merged = Support::autonomousCycleItem('a', 'p', 's1', [
            'cycle_id' => 'c-m',
            'final_status' => 'cycle_completed',
            'merge_performed' => true,
            'branch_ref' => 'feat/m',
            'sandbox_id' => 'sb',
            'selected_finding' => ['title' => 'landed'],
        ], false, true);
        $this->assertSame('autonomous_cycle_merged', $merged['kind']);
        $this->assertSame('insight', $merged['bucket']);

        $review = Support::autonomousCycleItem('a', 'p', 's1', [
            'cycle_id' => 'c-r',
            'final_status' => 'awaiting_review',
            'merge_performed' => false,
            'branch_ref' => 'feat/r',
            'sandbox_id' => 'sb',
            'selected_finding' => ['title' => 'review me'],
            'inbox_emitted_before_merge_attempt' => true,
        ], false, true);
        $this->assertSame('autonomous_cycle_review_required', $review['kind']);
        $this->assertSame('approval', $review['bucket']);
        $this->assertStringContainsString('ff-only merge', $review['payload']['ap786_cycle']['next_operator_action']);
    }

    public function test_items_from_autonomous_evolution_walks_sessions(): void
    {
        $items = Support::itemsFromAutonomousEvolution('a', 'p', [
            'sessions' => [
                [
                    'session_id' => 's1',
                    'claim_policy' => [
                        'direct_provider_driver_allowed' => false,
                        'requires_robust_obra_forge_quality_flow' => true,
                    ],
                    'cycles' => [
                        ['final_status' => 'dry_run_planned'],
                        [
                            'cycle_id' => 'real-1',
                            'final_status' => 'blocked',
                            'blockers' => ['missing_finding'],
                            'selected_finding' => ['title' => 'gap'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('autonomous_cycle_blocked', $items[0]['kind']);
        $this->assertStringContainsString('missing_finding', $items[0]['summary']);
    }

    public function test_cycle_validation_and_rollback_instructions(): void
    {
        $empty = Support::cycleValidation([]);
        $this->assertSame('not_run', $empty['status']);
        $this->assertFalse($empty['passed']);

        $passed = Support::cycleValidation([
            'validation' => ['status' => 'passed', 'commands' => ['phpunit']],
        ]);
        $this->assertTrue($passed['passed']);
        $this->assertSame(['phpunit'], $passed['commands']);

        $this->assertStringContainsString('Nothing to roll back', Support::rollbackInstruction('b', 's', false, true));
        $this->assertStringContainsString('git revert', Support::rollbackInstruction('b', 's', true, false));
        $this->assertStringContainsString('git branch -D b', Support::rollbackInstruction('b', 's', false, false));
    }

    public function test_counters_dedupe_and_empty_state(): void
    {
        $items = [
            Support::item('k1', 'alert', 'blocked', 't', 's', 'a', 'p', 'AP-1', 'd1'),
            Support::item('k2', 'approval', 'executed', 't', 's', 'a', 'p', 'AP-1', 'd2'),
            Support::item('k3', 'insight', 'deferred', 't', 's', 'a', 'p', 'AP-1', 'd2'), // dupe key
            Support::item('k4', 'recommendation', 'ready', 't', 's', 'a', 'p', 'AP-1', 'd3'),
        ];
        $deduped = Support::dedupeItems($items);
        $this->assertCount(3, $deduped);

        $counters = Support::counters($deduped);
        $this->assertSame(1, $counters['alerts']);
        $this->assertSame(1, $counters['approvals']);
        $this->assertSame(1, $counters['recommendations']);
        $this->assertSame(0, $counters['insights']);
        $this->assertSame(1, $counters['blocked']);
        $this->assertSame(1, $counters['executed']);
        $this->assertSame(0, $counters['deferred']);

        $empty = Support::emptyState(false, []);
        $this->assertTrue($empty['honest']);
        $this->assertFalse($empty['loading']);
        $this->assertSame('no_stewardship_loop_activity_recorded', $empty['reason']);

        $degraded = Support::emptyState(true, ['ap_777_readiness:error']);
        $this->assertSame('degraded_with_no_items', $degraded['reason']);
        $this->assertSame(['ap_777_readiness:error'], $degraded['failed_sources']);
    }

    public function test_latest_receipts_collectors_and_hash_identity(): void
    {
        $receipts = Support::latestReceipts(
            ['last_run' => ['id' => 'r1']],
            [
                'records' => [
                    [
                        'final_status' => ContinuousStewardshipDayStartService::STATUS_FIRST_TICK_EXECUTED,
                        'start_receipt_id' => 'sr1',
                        'recorded_at' => '2026-07-24T00:00:00Z',
                    ],
                ],
            ],
            ['cycles' => [['cycle_id' => 'c1']]],
            ['records' => [['bridge' => 1]]],
            ['receipts' => [['id' => 'rc1'], ['id' => 'rc2']]],
        );

        $sources = array_column($receipts, 'source');
        $this->assertContains('ap_766_runner', $sources);
        $this->assertContains('ap_778_day_start', $sources);
        $this->assertContains('ap_778_first_tick', $sources);
        $this->assertContains('ap_768_first_cycle', $sources);
        $this->assertContains('ap_765_runtime_bridge', $sources);
        $this->assertContains('ap_755_control_receipt', $sources);

        $blockers = Support::collectBlockers(
            ['blockers' => ['a', 'b', 'a']],
            ['blockers' => ['b', 'c']],
            null,
            ['blockers' => ['']],
        );
        $this->assertSame(['a', 'b', 'c'], $blockers);

        $actions = Support::collectNextActions(
            ['next_actions' => ['do_a']],
            ['next_actions' => ['do_a', 'do_b']],
            null,
        );
        $this->assertSame(['do_a', 'do_b'], $actions);

        $identity = Support::hashIdentity([
            'schema_version' => 'v1',
            'status' => 'ready',
            'area_id' => 'a',
            'portfolio_id' => 'p',
            'item_count' => 1,
            'counters' => ['alerts' => 1],
            'items' => [
                ['dedupe_key' => 'd1', 'kind' => 'k', 'cycle_state' => 'blocked'],
            ],
            'degraded_state' => ['failed_sources' => ['x']],
        ]);
        $this->assertSame('v1', $identity['schema_version']);
        $this->assertSame([['dedupe_key' => 'd1', 'kind' => 'k', 'cycle_state' => 'blocked']], $identity['items']);
        $this->assertSame(['x'], $identity['failed_sources']);
        $this->assertArrayNotHasKey('generated_at', $identity);
    }

    public function test_slug_and_short_hash_are_deterministic(): void
    {
        $this->assertSame('agentic_engineering_os', Support::slug('  Agentic Engineering OS  '));
        $this->assertSame('agentic_engineering_os', Support::slug('@@@'));
        $this->assertSame(12, strlen(Support::shortHash('worktree')));
        $this->assertSame(Support::shortHash('worktree'), Support::shortHash('worktree'));
    }

    public function test_owner_flow_stages_include_extra_required_aps(): void
    {
        $stages = Support::ownerFlowStages([
            'required_chain' => ['AP-747', 'AP-999-extra'],
        ], true);

        $aps = array_column($stages, 'ap');
        $this->assertContains('AP-747', $aps);
        $this->assertContains('AP-999-extra', $aps);
        $this->assertSame('required_not_satisfied', $stages[0]['status']);

        $owned = Support::ownerFlowStages([], false);
        $this->assertSame('owned', $owned[0]['status']);
        $this->assertCount(count(Support::AP786_OWNER_FLOW_CHAIN), $owned);
    }
}
