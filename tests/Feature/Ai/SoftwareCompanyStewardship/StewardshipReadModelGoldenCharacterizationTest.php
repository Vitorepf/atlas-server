<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompanyStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GOD-DEBULK golden characterization (2026-07-22) — Ai/Company step 1.
 *
 * Freezes the CURRENT response STRUCTURE of the Stewardship HTTP read models
 * (routes/api.php `ai/software-company-stewardship` GET group) before the
 * EnterpriseExecutorUnification restructure: Product Mode cockpit, Area Focus
 * health surface, loop areas picker and loop live status.
 *
 * What is frozen: exact top-level key lists (order included — assertSame on
 * arrays is order-sensitive), the stable scalar/honesty invariants
 * (schema_version, status, claim_policy, stack, kill-switch state, clean-disk
 * run_state) and the stable 404/401 behavior. Volatile fields (surface_hash,
 * generated_at, environment-derived counters/health values) are asserted by
 * TYPE only, never by value — the suite is deterministic on any machine.
 *
 * The loop runner is pointed at an isolated temp storage root so run_state is
 * clean-disk truth (no real ledger/lock leaks into the golden).
 */
final class StewardshipReadModelGoldenCharacterizationTest extends TestCase
{
    private const BASE = '/ai/software-company-stewardship';

    private const PORTFOLIO = 'atlas_software_company';

    private const AREA = 'agentic_engineering_os';

    private const COCKPIT_TOP_KEYS = [
        'schema_version', 'status', 'ap_contract', 'area_id', 'portfolio_id', 'read_only',
        'stack', 'source_ap_contracts', 'counters', 'health', 'area_focus',
        'executive_decision_inbox', 'new_area_proposal_gate', 'self_expanding_company',
        'stewardship_outcome_history', 'domain_runtime_creation_handoff',
        'area_stewardship_active_handoff', 'area_stewardship_active_operation',
        'continuous_stewardship_loop', 'continuous_stewardship_scheduler',
        'dev_forge_release', 'owner_sandbox_runtime_runner', 'owner_runtime_result_bridge',
        'executive_allocation_handoff', 'product_mode_operational_controls',
        'loop_24h_observability', 'review_queue', 'operator_controls', 'next_actions',
        'claim_policy', 'surface_hash', 'generated_at',
    ];

    private const COCKPIT_HEALTH_KEYS = [
        'overall', 'area_focus', 'executive_review_pending', 'new_area_blocked',
        'self_expanding_status', 'outcome_history_status',
        'domain_runtime_creation_handoff_status', 'area_stewardship_active_handoff_status',
        'area_stewardship_active_operation_status', 'continuous_stewardship_loop_status',
        'continuous_stewardship_scheduler_status', 'dev_forge_release_status',
        'owner_sandbox_runtime_runner_status', 'owner_runtime_result_bridge_status',
        'executive_allocation_handoff_status', 'product_mode_operational_controls_status',
    ];

    private const COCKPIT_CLAIM_POLICY = [
        'read_only_over_repo' => true,
        'surface_only' => true,
        'writes_local_state' => false,
        'records_operator_decisions' => false,
        'provider_invoked' => false,
        'dev_invoked' => false,
        'forge_invoked' => false,
        'branch_created' => false,
        'worktree_created' => false,
        'domain_runtime_created' => false,
        'department_created' => false,
        'scheduler_installed' => false,
        'continuous_loop_tick_executed_by_cockpit' => false,
        'recurring_scheduler_executed_by_cockpit' => false,
        'dev_forge_release_executed_by_cockpit' => false,
        'owner_queue_consumption_executed_by_cockpit' => false,
        'owner_sandbox_runtime_runner_executed_by_cockpit' => false,
        'owner_runtime_result_bridge_executed_by_cockpit' => false,
        'executive_allocation_handoff_executed_by_cockpit' => false,
        'product_mode_controls_execute_actions' => false,
        'product_mode_controls_authorize_repository' => false,
        'product_mode_controls_change_autonomy_tier' => false,
        'new_os_created' => false,
        'parallel_runtime_created' => false,
        'merge_without_operator' => false,
        'deploy_without_operator' => false,
        'secret_access' => false,
        'autoapproval_allowed' => false,
        'autoimplementation_allowed' => false,
        'auto_promotion' => false,
        'operator_review_required' => true,
    ];

    private const COCKPIT_STACK = [
        'name' => 'Atlas Software Company Stewardship Stack',
        'parent_runtime' => 'Atlas Autonomous Software Company Runtime',
        'current_visual_level' => 'Night Shift Product Mode',
        'continuous_motor' => 'Atlas Continuous Stewardship Loop',
        'area_focus' => 'Area Focus Loop',
        'ceiling' => 'Self-Expanding Software Company',
        'not_a_new_os' => true,
    ];

    private const AREA_FOCUS_TOP_KEYS = [
        'schema_version', 'status', 'area_id', 'read_only', 'ap_contract', 'source_loop',
        'stewardship_stack_note', 'area_summary', 'health', 'findings', 'inbox_items',
        'work_orders', 'budgets', 'evidence_packs', 'kill_switch_state', 'next_actions',
        'claim_policy', 'surface_hash', 'generated_at',
    ];

    private const AREA_FOCUS_CLAIM_POLICY = [
        'read_only' => true,
        'writes_state' => false,
        'provider_invoked' => false,
        'executes_work' => false,
        'opens_branch' => false,
        'dispatches_to_dev_or_forge' => false,
        'routes_to_dev_or_forge' => false,
        'creates_spec' => false,
        'creates_branch_sandbox' => false,
        'autoapproval_allowed' => false,
        'external_side_effect_allowed' => false,
        'runtime_authority_reconciled' => true,
        'composes_ap716_core_read_model' => true,
        'core_read_model_owner' => 'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\AtlasAreaFocusLoopReadModelService',
        'slice_ap' => 'AP-712',
        'parallel_runtime_created' => false,
        'parallel_authority_created' => false,
        'is_new_os' => false,
        'reuses_self_directed_evolution_for_gaps' => true,
        'operator_review_required' => true,
    ];

    private const AREA_FOCUS_KILL_SWITCH_STATE = [
        'required' => true,
        'engaged' => false,
        'controlled_by' => 'operator',
        'execution_enabled' => false,
        'note' => 'Kill switch is required by max_governed. Read-only surface has nothing running to stop; an operator-controlled runtime toggle arrives with execution slices.',
    ];

    private const AREA_FOCUS_BUDGET_KEYS = [
        'dev_budget', 'forge_budget', 'wip_limit', 'wip_used', 'dev_routed',
        'forge_routed', 'queued', 'budget_consumed', 'execution_executed',
    ];

    private const AREAS_TOP_KEYS = [
        'schema_version', 'read_only', 'areas', 'area_count', 'default_area',
        'default_focus', 'surface_hash', 'generated_at',
    ];

    private const AREAS_PER_AREA_KEYS = [
        'area_id', 'area_name', 'focus', 'autonomy_tier', 'max_tier_for_area', 'dev_mode',
        'registered', 'objective', 'owned_systems', 'repo_scope', 'stop_conditions',
        'run_state',
    ];

    private const LIVE_TOP_KEYS = [
        'schema_version', 'area_id', 'focus', 'portfolio_id', 'read_only', 'cockpit',
        'run_state', 'surface_hash', 'generated_at',
    ];

    private const LIVE_RUN_STATE_KEYS = ['lock', 'kill_switch', 'pause', 'stewardship_recovery', 'scheduler_backlog'];

    private const LIVE_STEWARDSHIP_RECOVERY_KEYS = ['schema_version', 'area_id', 'focus', 'inputs', 'merge_eligibility', 'outputs'];

    private const LIVE_SCHEDULER_BACKLOG_KEYS = [
        'schema_version', 'ap790_backlog_item', 'bounded_by', 'outcome_counts', 'recovery',
        'recent_cycles', 'lock', 'kill_switch', 'pause', 'ledger_record_count',
    ];

    /** @var array<string,string> */
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');

        // Clean-disk run state: no real loop ledger/lock leaks into the golden.
        $this->storage = sys_get_temp_dir().'/atlas-steward-golden-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->storage);
        $runner = app(Reliable24hLoopRunnerService::class);
        $runner->setStorageRootForTesting($this->storage);
        $this->app->instance(Reliable24hLoopRunnerService::class, $runner);
    }

    protected function tearDown(): void
    {
        if ($this->storage !== '') {
            File::deleteDirectory($this->storage);
        }
        parent::tearDown();
    }

    public function test_every_read_model_get_requires_the_atlas_token(): void
    {
        foreach ([
            self::BASE.'/product-mode-cockpit/'.self::PORTFOLIO,
            self::BASE.'/area-focus/'.self::AREA,
            self::BASE.'/loop/areas',
            self::BASE.'/loop/'.self::AREA.'/live',
        ] as $path) {
            $this->getJson($path)->assertStatus(401);
        }
    }

    public function test_product_mode_cockpit_read_model_structure_is_frozen(): void
    {
        $body = $this->getFrozen(self::BASE.'/product-mode-cockpit/'.self::PORTFOLIO);

        $this->assertSame(self::COCKPIT_TOP_KEYS, array_keys($body));
        $this->assertSame('atlas.software_company.product_mode_cockpit.v1', $body['schema_version']);
        $this->assertSame('ready', $body['status']);
        $this->assertSame('AP-739', $body['ap_contract']);
        $this->assertSame(self::AREA, $body['area_id']);
        $this->assertSame(self::PORTFOLIO, $body['portfolio_id']);
        $this->assertTrue($body['read_only']);
        $this->assertSame(self::COCKPIT_STACK, $body['stack']);
        $this->assertSame(self::COCKPIT_CLAIM_POLICY, $body['claim_policy']);
        // Health VALUES are environment-derived; the key set is the contract.
        $this->assertSame(self::COCKPIT_HEALTH_KEYS, array_keys($body['health']));
        foreach ($body['counters'] as $counter => $value) {
            $this->assertIsInt($value, 'counter '.$counter.' must stay an integer');
        }
    }

    public function test_area_focus_read_model_structure_is_frozen(): void
    {
        $body = $this->getFrozen(self::BASE.'/area-focus/'.self::AREA);

        $this->assertSame(self::AREA_FOCUS_TOP_KEYS, array_keys($body));
        $this->assertSame('atlas.night_shift.area_focus_product_mode_surface.v1', $body['schema_version']);
        $this->assertSame('ready', $body['status']);
        $this->assertSame('AP-721', $body['ap_contract']);
        $this->assertSame(self::AREA, $body['area_id']);
        $this->assertTrue($body['read_only']);
        $this->assertSame(self::AREA_FOCUS_CLAIM_POLICY, $body['claim_policy']);
        $this->assertSame(self::AREA_FOCUS_KILL_SWITCH_STATE, $body['kill_switch_state']);
        $this->assertSame(self::AREA_FOCUS_BUDGET_KEYS, array_keys($body['budgets']));
        $this->assertIsArray($body['findings']);
        $this->assertIsArray($body['work_orders']);
        $this->assertIsArray($body['inbox_items']);
    }

    public function test_loop_areas_read_model_structure_is_frozen(): void
    {
        $body = $this->getFrozen(self::BASE.'/loop/areas');

        $this->assertSame(self::AREAS_TOP_KEYS, array_keys($body));
        $this->assertSame('atlas.software_company_stewardship.loop_command_areas.v1', $body['schema_version']);
        $this->assertTrue($body['read_only']);
        $this->assertSame(self::AREA, $body['default_area']);
        $this->assertSame('dev_forge', $body['default_focus']);
        $this->assertSame(3, $body['area_count']);
        $this->assertSame(
            [self::AREA, 'atlas_loop_factory', 'atlas-native'],
            array_column($body['areas'], 'area_id'),
        );
        foreach ($body['areas'] as $area) {
            $this->assertSame(self::AREAS_PER_AREA_KEYS, array_keys($area), $area['area_id']);
            // Clean temp storage: no lock is ever held in the golden run.
            $this->assertFalse($area['run_state']['lock']['held'], $area['area_id']);
        }
    }

    public function test_loop_live_read_model_structure_is_frozen(): void
    {
        $body = $this->getFrozen(self::BASE.'/loop/'.self::AREA.'/live');

        $this->assertSame(self::LIVE_TOP_KEYS, array_keys($body));
        $this->assertSame('atlas.software_company_stewardship.loop_command_live.v1', $body['schema_version']);
        $this->assertSame(self::AREA, $body['area_id']);
        $this->assertSame('dev_forge', $body['focus']);
        $this->assertSame(self::PORTFOLIO, $body['portfolio_id']);
        $this->assertTrue($body['read_only']);
        // The mobile surface receives ONLY the public cockpit summary.
        $this->assertSame(
            ['schema_version' => 'atlas.autonomos.cockpit_summary.v1', 'status' => 'ready'],
            $body['cockpit'],
        );
        $this->assertSame(self::LIVE_RUN_STATE_KEYS, array_keys($body['run_state']));
        // Clean-disk truth: nothing running, nothing killed, nothing paused.
        $this->assertSame(
            ['available' => true, 'held' => false, 'holder' => null],
            $body['run_state']['lock'],
        );
        $this->assertSame(['active' => false], $body['run_state']['kill_switch']);
        $this->assertSame(['active' => false], $body['run_state']['pause']);
        $this->assertSame(
            self::LIVE_STEWARDSHIP_RECOVERY_KEYS,
            array_keys($body['run_state']['stewardship_recovery']),
        );
        $this->assertSame(
            self::LIVE_SCHEDULER_BACKLOG_KEYS,
            array_keys($body['run_state']['scheduler_backlog']),
        );
    }

    public function test_loop_live_unknown_area_is_a_stable_404(): void
    {
        $response = $this->getJson(self::BASE.'/loop/___golden_nope___/live', $this->headers);

        $response->assertStatus(404);
        $body = (array) $response->json();
        $this->assertSame(['error'], array_keys($body));
        $this->assertSame('unknown_area', $body['error']['code']);
        $this->assertContains(self::AREA, $body['error']['supported_areas']);
    }

    /**
     * GET the path with the token, assert 200 + the volatile-field contract
     * (surface_hash/generated_at present as non-empty strings), return the body.
     *
     * @return array<string,mixed>
     */
    private function getFrozen(string $path): array
    {
        $response = $this->getJson($path, $this->headers);
        $this->assertSame(200, $response->getStatusCode(), $path.' -> '.$response->getContent());
        $this->assertInstanceOf(TestResponse::class, $response);

        $body = (array) $response->json();
        $this->assertIsString($body['surface_hash'] ?? null, $path);
        $this->assertNotSame('', $body['surface_hash'], $path);
        $this->assertIsString($body['generated_at'] ?? null, $path);

        return $body;
    }
}
