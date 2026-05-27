<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Ap786RobustForgeQualityContractService;
use Tests\TestCase;

final class Ap786RobustForgeQualityContractServiceTest extends TestCase
{
    private function service(): Ap786RobustForgeQualityContractService
    {
        return new Ap786RobustForgeQualityContractService;
    }

    /**
     * A complete input that satisfies every one of the 12 robust-flow capabilities.
     *
     * @return array<string,mixed>
     */
    private function completeInput(): array
    {
        return [
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'owner' => 'atlas_dev',
            'selected_finding' => [
                'finding_id' => 'find_1',
                'finding_hash' => 'h1',
                'title' => 'Harden AP-786 wasted-cycle guard',
                'kind' => 'bug',
                'severity' => 'high',
                'why_it_matters' => 'Operator stops wasting autonomous cycles on no-op commits.',
                'allowed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/X.php'],
                'spec_seed' => ['candidate_id' => 'spec_1', 'objective' => 'Block no-op cycles', 'acceptance' => ['No-op cycle is blocked']],
                'expected_test_path' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/XTest.php',
            ],
            'allowed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/X.php'],
            'sandbox' => [
                'sandbox_id' => 'afsb_1',
                'materialization' => ['branch_name' => 'area-focus/agentic-engineering-os/x', 'worktree_path' => '/tmp/wt_x'],
            ],
            'validation_commands' => [
                'php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/XTest.php',
                'git diff --check',
            ],
            'tdd' => [
                'tests_required' => ['tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/XTest.php'],
                'test_first' => true,
                'focused_test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/XTest.php',
            ],
            'bdd' => [
                'behavior_acceptance' => ['Given a no-op cycle, When it runs, Then it is blocked with evidence'],
                'operator_visible_outcome' => 'Operator sees a blocked no-op cycle instead of a wasted commit.',
            ],
            'provider_topology' => ['source' => 'atlas_decide', 'chosen_by_atlas_decide' => true],
            'workcell' => [
                'context_scout' => 'agent_a',
                'architect' => 'agent_b',
                'implementer' => 'agent_c',
                'reviewer' => 'agent_d',
                'repair_agent' => 'agent_e',
                'certifier' => 'agent_f',
            ],
            'repair_policy' => [
                'max_attempts' => 2,
                'failed_gate_capsule_schema' => 'atlas.software_company_stewardship.ap786_failed_gate_capsule.v1',
                'stop_conditions' => ['max_attempts_reached', 'validation_still_failing'],
            ],
            'evidence' => [
                'decision_receipt_id' => 'dr_1',
                'evidence_ledger_ref' => 'el_1',
                'ap750' => 'ap750_result_1',
                'replay_ref' => 'replay_1',
                'programming_governance' => true,
            ],
            'merge' => ['governed_by' => ['AP-769', 'AP-774']],
        ];
    }

    public function test_ready_when_all_minimum_inputs_exist(): void
    {
        $contract = $this->service()->build($this->completeInput());

        $this->assertSame(Ap786RobustForgeQualityContractService::CONTRACT_SCHEMA, $contract['schema_version']);
        $this->assertSame(Ap786RobustForgeQualityContractService::STATUS_READY, $contract['status']);
        $this->assertSame([], $contract['blockers']);
        $this->assertSame([], $contract['missing_capabilities']);
        $this->assertCount(12, $contract['capabilities']);
        $this->assertSame(
            Ap786RobustForgeQualityContractService::REQUIRED_CAPABILITIES,
            array_column($contract['capabilities'], 'name'),
        );
        foreach ($contract['capabilities'] as $cap) {
            $this->assertTrue($cap['ok'], 'capability not ok: '.$cap['name']);
        }
        $this->assertFalse($contract['claim_policy']['provider_invoked']);
        $this->assertFalse($contract['claim_policy']['mutates_repo']);
        $this->assertFalse($contract['claim_policy']['merge_performed']);
        // Packets present and shaped.
        $this->assertSame(['AP-769', 'AP-774'], $contract['merge_requirements']['governed_by']);
        $this->assertSame(Ap786RobustForgeQualityContractService::REQUIRED_CAPABILITIES, array_column($contract['capabilities'], 'name'));
    }

    public function test_blocked_when_tdd_contract_missing(): void
    {
        $input = $this->completeInput();
        unset($input['tdd']);

        $contract = $this->service()->build($input);

        $this->assertSame(Ap786RobustForgeQualityContractService::STATUS_BLOCKED, $contract['status']);
        $this->assertContains('tdd_test_contract', $contract['missing_capabilities']);
        $this->assertContains('missing_capability:tdd_test_contract', $contract['blockers']);
        $this->assertSame([], $contract['tdd_contract']['tests_required']);
        $this->assertFalse($contract['tdd_contract']['ok']);
        // Other capabilities still pass — only TDD is missing.
        $this->assertNotContains('atlas_decide_provider_topology', $contract['missing_capabilities']);
    }

    public function test_blocked_when_tests_declared_but_not_test_first(): void
    {
        $input = $this->completeInput();
        $input['tdd']['test_first'] = false;

        $contract = $this->service()->build($input);

        $this->assertSame(Ap786RobustForgeQualityContractService::STATUS_BLOCKED, $contract['status']);
        $this->assertContains('tests_declared_but_not_test_first', $contract['blockers']);
    }

    public function test_blocked_when_provider_direct_claim(): void
    {
        $input = $this->completeInput();
        $input['provider_topology'] = ['source' => 'direct', 'chosen_by_atlas_decide' => false];
        $input['direct_provider_claim'] = true;

        $contract = $this->service()->build($input);

        $this->assertSame(Ap786RobustForgeQualityContractService::STATUS_BLOCKED, $contract['status']);
        $this->assertContains('atlas_decide_provider_topology', $contract['missing_capabilities']);
        $this->assertContains('direct_provider_path_claimed_forbidden', $contract['blockers']);
        $this->assertFalse($contract['provider_topology_requirement']['ok']);
        $this->assertTrue($contract['provider_topology_requirement']['direct_provider_claim']);
    }

    public function test_blocked_when_provider_path_is_direct_driver(): void
    {
        $input = $this->completeInput();
        $input['provider_path'] = 'direct_provider_driver';
        unset($input['provider_topology']);

        $contract = $this->service()->build($input);

        $this->assertSame(Ap786RobustForgeQualityContractService::STATUS_BLOCKED, $contract['status']);
        $this->assertContains('direct_provider_path_claimed_forbidden', $contract['blockers']);
    }

    public function test_output_is_deterministic(): void
    {
        $input = $this->completeInput();
        $first = $this->service()->build($input);
        $second = $this->service()->build($input);

        $this->assertSame($first['contract_hash'], $second['contract_hash']);

        unset($first['generated_at'], $second['generated_at']);
        $this->assertSame($first, $second);
    }

    public function test_owner_is_normalized(): void
    {
        $input = $this->completeInput();
        $input['owner'] = 'atlas_forge';

        $contract = $this->service()->build($input);

        $this->assertSame('forge', $contract['owner']);
        $this->assertSame('forge', $contract['provider_topology_requirement']['owner']);
    }
}
