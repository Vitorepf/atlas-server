<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionSessionStoreService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\LaneExecutionContractService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentLaneOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AP-802 · every lane of a multi-agent Stewardship cycle must carry a separated
 * role, context, authority, provider plan, evidence obligations, output schema
 * and durable receipt — so "multi-agent" can never collapse into one generic
 * prompt with shared context and shared write power.
 */
final class LaneExecutionContractServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap802_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    /**
     * @return array{0:LaneExecutionContractService,1:AgentExecutionSessionStoreService}
     */
    private function service(): array
    {
        $store = app(AgentExecutionSessionStoreService::class);
        $store->setStorageRootForTesting($this->tmp.'/sessions');
        // Use the deterministic AP-802 canonical lane builder (null orchestrator)
        // so this contract suite is decoupled from the concurrently-evolving
        // AP-797 orchestrator. Production wiring still composes the orchestrator.
        $svc = new LaneExecutionContractService(null, $store);

        return [$svc, $store];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function slice(array $overrides = []): array
    {
        return array_merge([
            'slice_id' => 'slice_x',
            'owner' => 'atlas_dev',
            'risk_level' => 'medium',
            'objective' => 'Harden the router selection.',
            'allowed_files' => ['app/Services/Ai/Example.php', 'tests/Unit/Ai/ExampleTest.php'],
            'forbidden_files' => ['config/app.php'],
            'validation_commands' => ['php artisan test tests/Unit/Ai/ExampleTest.php'],
            'evidence_obligations' => ['focused_test_pass'],
            'max_runtime_seconds' => 1800,
            'provider_fit' => 'cursor_cli:composer-2.5',
        ], $overrides);
    }

    /**
     * A canonical, valid 5-lane plan fixture that validation tests can tamper.
     *
     * @param  array<string,array<string,mixed>>  $laneOverrides  keyed by role
     * @return array<string,mixed>
     */
    private function lanePlan(array $laneOverrides = []): array
    {
        $defs = [
            ['role' => 'context_scout', 'write_authority' => 'read_only', 'allowed_actions' => ['read_files', 'grep']],
            ['role' => 'architect', 'write_authority' => 'spec_only', 'allowed_actions' => ['read_files', 'draft_spec']],
            ['role' => 'implementer', 'write_authority' => 'worktree_write', 'allowed_actions' => ['read_files', 'write_allowed_files', 'commit_to_worktree']],
            ['role' => 'reviewer', 'write_authority' => 'read_only', 'allowed_actions' => ['read_files', 'read_diff', 'annotate_review']],
            ['role' => 'judge', 'write_authority' => 'read_only_no_merge', 'allowed_actions' => ['read_files', 'select_best_candidate']],
        ];

        $lanes = [];
        foreach ($defs as $i => $def) {
            $role = $def['role'];
            $lane = array_merge([
                'lane_id' => 'lane_'.$role,
                'sequence' => $i + 1,
                'output_schema' => 'atlas.agent_execution.lane_output.'.$role.'.v1',
            ], $def);
            if (isset($laneOverrides[$role])) {
                $lane = array_merge($lane, $laneOverrides[$role]);
            }
            $lanes[] = $lane;
        }

        return ['schema_version' => MultiAgentLaneOrchestratorService::PLAN_SCHEMA, 'mode' => 'plan_only', 'lanes' => $lanes];
    }

    public function test_creates_lane_contracts_for_the_six_lanes(): void
    {
        [$svc] = $this->service();

        $receipt = $svc->harden([
            'executable_slice' => $this->slice(),
            'execute' => true,
            // judge_blocked forces the conditional repair_agent lane in.
            'judge_blocked' => true,
            'cycle_id' => 'cyc_1',
            'session_id' => 'aess_1',
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
        ]);

        $this->assertSame(LaneExecutionContractService::STATUS_HARDENED, $receipt['status']);
        $this->assertSame(6, $receipt['lane_count']);

        $roles = array_map(static fn (array $c): string => $c['role'], $receipt['lane_contracts']);
        $this->assertSame(
            ['context_scout', 'architect', 'implementer', 'reviewer', 'repair_agent', 'judge'],
            $roles,
        );

        // Every lane declares the full separated contract.
        foreach ($receipt['lane_contracts'] as $c) {
            foreach (['lane_id', 'role', 'input_context_refs', 'allowed_actions', 'forbidden_actions',
                'write_authority', 'provider_plan', 'evidence_obligations', 'output_schema', 'status', 'blockers'] as $field) {
                $this->assertArrayHasKey($field, $c, "lane contract missing {$field}");
            }
        }

        // Only the implementer (and conditional repair_agent) hold write authority.
        $this->assertTrue($receipt['fake_multi_agent_guard']['is_real_multi_agent']);
        $this->assertTrue($receipt['fake_multi_agent_guard']['single_write_lane']);
        $this->assertTrue($receipt['fake_multi_agent_guard']['distinct_output_schema_per_lane']);
    }

    public function test_blocks_reviewer_with_write_authority(): void
    {
        [$svc] = $this->service();

        $result = $svc->validateLanePlan($this->lanePlan([
            'reviewer' => ['write_authority' => true],
        ]));

        $this->assertFalse($result['valid']);
        $this->assertContains(LaneExecutionContractService::BLOCK_REVIEWER_WRITE, $result['blockers']);
    }

    public function test_blocks_judge_with_write_or_merge_actions(): void
    {
        [$svc] = $this->service();

        $result = $svc->validateLanePlan($this->lanePlan([
            'judge' => ['allowed_actions' => ['read_files', 'write_allowed_files']],
        ]));

        $this->assertFalse($result['valid']);
        $this->assertContains(LaneExecutionContractService::BLOCK_JUDGE_WRITE_OR_MERGE, $result['blockers']);

        // Also blocks a judge that tries to merge.
        $merge = $svc->validateLanePlan($this->lanePlan([
            'judge' => ['allowed_actions' => ['read_files', 'merge_to_main']],
        ]));
        $this->assertContains(LaneExecutionContractService::BLOCK_JUDGE_WRITE_OR_MERGE, $merge['blockers']);
    }

    public function test_repair_agent_disabled_without_failure(): void
    {
        [$svc] = $this->service();

        $receipt = $svc->harden([
            'executable_slice' => $this->slice(),
            'execute' => true,
            'cycle_id' => 'cyc_clean',
            'session_id' => 'aess_clean',
        ]);

        $roles = array_map(static fn (array $c): string => $c['role'], $receipt['lane_contracts']);
        $this->assertSame(['context_scout', 'architect', 'implementer', 'reviewer', 'judge'], $roles);
        $this->assertNotContains('repair_agent', $roles);
        $this->assertFalse($receipt['validation']['repair_enabled']);
        $this->assertSame(LaneExecutionContractService::STATUS_HARDENED, $receipt['status']);
    }

    public function test_repair_agent_enabled_with_judge_blocked(): void
    {
        [$svc] = $this->service();

        $receipt = $svc->harden([
            'executable_slice' => $this->slice(),
            'execute' => true,
            'judge_blocked' => true,
            'cycle_id' => 'cyc_repair',
            'session_id' => 'aess_repair',
        ]);

        $roles = array_map(static fn (array $c): string => $c['role'], $receipt['lane_contracts']);
        $this->assertContains('repair_agent', $roles);
        $this->assertTrue($receipt['validation']['repair_enabled']);
        $this->assertTrue($receipt['validation']['valid']);

        // A repair lane present WITHOUT a failure is rejected.
        $bad = $svc->validateLanePlan(array_merge($this->lanePlan(), [
            'lanes' => array_merge($this->lanePlan()['lanes'], [[
                'lane_id' => 'lane_repair_agent',
                'role' => 'repair_agent',
                'write_authority' => 'repair_branch_write',
                'allowed_actions' => ['read_files', 'write_repair_branch_files'],
                'output_schema' => 'atlas.agent_execution.lane_output.repair_agent.v1',
            ]]),
        ]));
        $this->assertContains(LaneExecutionContractService::BLOCK_REPAIR_WITHOUT_FAILURE, $bad['blockers']);
    }

    public function test_session_store_records_one_session_per_lane(): void
    {
        [$svc, $store] = $this->service();

        $receipt = $svc->harden([
            'executable_slice' => $this->slice(),
            'execute' => true,
            'judge_blocked' => true,
            'cycle_id' => 'cyc_sessions',
            'session_id' => 'aess_sessions',
            'bind_sessions' => true,
        ]);

        $this->assertCount(6, $receipt['lane_session_refs']);
        $this->assertSame(6, $receipt['lane_count']);
        $this->assertCount(6, $store->all());

        // One distinct durable session per lane, all grouped under the loop session.
        $ids = array_map(static fn (array $r): string => $r['agent_session_id'], $receipt['lane_session_refs']);
        $this->assertCount(6, array_unique($ids));
        $this->assertCount(6, $store->replay('aess_sessions'));

        // Governance lanes never claim a provider invocation.
        foreach ($receipt['lane_session_refs'] as $ref) {
            if (! in_array($ref['role'], ['implementer', 'repair_agent'], true)) {
                $this->assertFalse($ref['provider_invoked'], "governance lane {$ref['role']} must not claim a provider run");
            }
        }
    }

    public function test_cycle_certification_blocks_when_lane_receipt_missing(): void
    {
        [$svc] = $this->service();

        $contracts = [];
        foreach (['context_scout', 'architect', 'implementer', 'reviewer', 'judge'] as $role) {
            $contracts[] = $svc->contractFor(['lane_id' => 'lane_'.$role, 'role' => $role]);
        }
        // Receipts for only four of the five lanes (judge has no receipt).
        $refs = [];
        foreach (['context_scout', 'architect', 'implementer', 'reviewer'] as $role) {
            $refs[] = ['role' => $role, 'agent_session_id' => 'ases_'.$role, 'session_hash' => 'sha256:'.$role];
        }

        $cert = $svc->certifyLaneReceipts($contracts, $refs);

        $this->assertFalse($cert['certified']);
        $this->assertContains('judge', $cert['missing_receipts']);
        $this->assertContains(LaneExecutionContractService::BLOCK_LANE_RECEIPT_MISSING, $cert['blockers']);

        // And harden() with sessions unbound surfaces the same block on the cycle.
        $receipt = $svc->harden([
            'executable_slice' => $this->slice(),
            'execute' => true,
            'bind_sessions' => false,
        ]);
        $this->assertSame(LaneExecutionContractService::STATUS_BLOCKED, $receipt['status']);
        $this->assertContains(LaneExecutionContractService::BLOCK_LANE_RECEIPT_MISSING, $receipt['blockers']);
    }

    public function test_deterministic_contract_set_hash_and_no_side_effects(): void
    {
        [$svc] = $this->service();
        $input = [
            'executable_slice' => $this->slice(),
            'execute' => true,
            'cycle_id' => 'cyc_det',
            'session_id' => 'aess_det',
        ];

        $a = $svc->harden($input);
        $b = $svc->harden($input);

        $this->assertSame($a['contract_set_hash'], $b['contract_set_hash']);
        $this->assertStringStartsWith('sha256:', $a['contract_set_hash']);

        $policy = $a['claim_policy'];
        $this->assertFalse($policy['invokes_provider']);
        $this->assertFalse($policy['merges']);
        $this->assertFalse($policy['opens_branch']);
        $this->assertFalse($policy['mutates_source_worktree']);
    }

    public function test_legacy_single_agent_path_unaffected_when_flag_off(): void
    {
        $service = app(AutonomousEvolutionSessionService::class);
        $service->setStorageDirForTesting($this->tmp.'/legacy-sessions');
        $quarantine = app(AreaFocusCandidateQuarantineService::class);
        $quarantine->setStorageRootForTesting($this->tmp.'/legacy-quarantine');
        $service->setCandidateQuarantineForTesting($quarantine);

        $payload = $service->run([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'cycles' => 1,
            'execute' => false,
        ]);

        // Flag off => no multi-agent workcell and no AP-802 lane contracts leak in.
        $this->assertArrayNotHasKey('multi_agent_workcell', $payload);
        $this->assertArrayNotHasKey('lane_contracts', $payload);
        foreach (($payload['cycles'] ?? []) as $cycle) {
            $this->assertArrayNotHasKey('lane_contracts', $cycle);
        }
    }
}
