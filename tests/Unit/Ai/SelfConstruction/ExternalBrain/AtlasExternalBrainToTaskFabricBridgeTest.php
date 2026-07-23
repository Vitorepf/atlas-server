<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainToTaskFabricBridge;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainToTaskFabricBridgeTest extends TestCase
{
    private function bridge(): AtlasExternalBrainToTaskFabricBridge
    {
        return new AtlasExternalBrainToTaskFabricBridge;
    }

    private function validProposal(array $overrides = []): array
    {
        return array_merge([
            'objective'           => 'Harden AtlasFooService so it rejects malformed input',
            'leverage_reason'     => 'Prevents malformed input from corrupting downstream state',
            'allowed_files'       => ['app/Services/AtlasFooService.php', 'tests/Unit/AtlasFooServiceTest.php'],
            'acceptance_criteria' => ['php artisan test tests/Unit/AtlasFooServiceTest.php passes'],
            'required_evidence'   => ['tests_or_gates_result'],
            'finding'             => 'Malformed input reaches downstream state without a typed rejection.',
            'baseline'            => 'Current path accepts malformed input.',
            'expected_structural_delta' => 'Reject malformed input at the service boundary.',
            'red_behavior'        => 'A malformed input fixture fails before the change.',
            'green_acceptance'     => 'The same fixture is rejected and the focused suite passes.',
            'rollback'             => 'Revert the scoped service change and restore the prior contract.',
            'outcome_metric'       => 'Malformed-input rejection rate remains 100 percent.',
        ], $overrides);
    }

    public function test_schema_present(): void
    {
        $result = $this->bridge()->bridge($this->validProposal());

        $this->assertSame(AtlasExternalBrainToTaskFabricBridge::SCHEMA, $result['schema']);
    }

    // ── accepted proposal ────────────────────────────────────────────────────

    public function test_valid_proposal_is_accepted_as_task_spec(): void
    {
        $result = $this->bridge()->bridge($this->validProposal());

        $this->assertTrue($result['accepted']);
        $this->assertSame([], $result['rejection_reasons']);
        $this->assertNotNull($result['task_spec']);
        foreach (['objective', 'allowed_files', 'acceptance_criteria', 'required_evidence'] as $key) {
            $this->assertArrayHasKey($key, $result['task_spec']);
        }
    }

    public function test_leverage_reason_is_preserved_verbatim_in_task_spec(): void
    {
        $result = $this->bridge()->bridge($this->validProposal([
            'leverage_reason' => 'Closes a data-loss path in the queue drain',
        ]));

        $this->assertSame('Closes a data-loss path in the queue drain', $result['task_spec']['leverage_reason']);
    }

    // ── rejection: duplicate ─────────────────────────────────────────────────

    public function test_duplicate_flag_rejects_proposal(): void
    {
        $result = $this->bridge()->bridge($this->validProposal(['is_duplicate' => true]));

        $this->assertFalse($result['accepted']);
        $this->assertNull($result['task_spec']);
        $this->assertContains('duplicate_proposal', $result['rejection_reasons']);
    }

    public function test_duplicate_of_rejects_with_target_in_reason(): void
    {
        $result = $this->bridge()->bridge($this->validProposal(['duplicate_of' => 'task-existing-42']));

        $this->assertFalse($result['accepted']);
        $this->assertContains('duplicate_proposal:task-existing-42', $result['rejection_reasons']);
    }

    // ── rejection: template-farm ─────────────────────────────────────────────

    public function test_template_placeholder_in_objective_is_rejected(): void
    {
        $result = $this->bridge()->bridge($this->validProposal([
            'objective' => 'Harden {ClassName} so it {does something}',
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertContains('template_farm_proposal', $result['rejection_reasons']);
    }

    public function test_template_farm_flag_is_rejected(): void
    {
        $result = $this->bridge()->bridge($this->validProposal(['template_farm' => true]));

        $this->assertFalse($result['accepted']);
        $this->assertContains('template_farm_proposal', $result['rejection_reasons']);
    }

    // ── rejection: test-only ─────────────────────────────────────────────────

    public function test_test_only_allowed_files_is_rejected(): void
    {
        $result = $this->bridge()->bridge($this->validProposal([
            'allowed_files' => ['tests/Unit/AtlasFooServiceTest.php'],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertContains('test_only_proposal', $result['rejection_reasons']);
    }

    // ── rejection: insufficient allowed_files ────────────────────────────────

    public function test_empty_allowed_files_is_rejected(): void
    {
        $result = $this->bridge()->bridge($this->validProposal(['allowed_files' => []]));

        $this->assertFalse($result['accepted']);
        $this->assertContains('insufficient_allowed_files', $result['rejection_reasons']);
    }

    // ── rejection: non-runnable acceptance ───────────────────────────────────

    public function test_empty_acceptance_criteria_is_rejected(): void
    {
        $result = $this->bridge()->bridge($this->validProposal(['acceptance_criteria' => []]));

        $this->assertFalse($result['accepted']);
        $this->assertContains('non_runnable_acceptance', $result['rejection_reasons']);
    }

    public function test_acceptance_criteria_without_gate_keyword_is_rejected(): void
    {
        $result = $this->bridge()->bridge($this->validProposal([
            'acceptance_criteria' => ['it should feel done'],
        ]));

        $this->assertFalse($result['accepted']);
        $this->assertContains('non_runnable_acceptance', $result['rejection_reasons']);
    }

    // ── rejection: missing required evidence / leverage reason / objective ──

    public function test_missing_required_evidence_is_rejected(): void
    {
        $result = $this->bridge()->bridge($this->validProposal(['required_evidence' => []]));

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing_required_evidence', $result['rejection_reasons']);
    }

    public function test_missing_leverage_reason_is_rejected(): void
    {
        $result = $this->bridge()->bridge($this->validProposal(['leverage_reason' => '']));

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing_leverage_reason', $result['rejection_reasons']);
    }

    public function test_missing_structural_quality_contract_is_rejected(): void
    {
        $proposal = $this->validProposal();
        unset(
            $proposal['finding'],
            $proposal['baseline'],
            $proposal['expected_structural_delta'],
            $proposal['red_behavior'],
            $proposal['green_acceptance'],
            $proposal['rollback'],
            $proposal['outcome_metric'],
        );

        $result = $this->bridge()->bridge($proposal);

        $this->assertFalse($result['accepted']);
        foreach (['finding', 'baseline', 'expected_structural_delta', 'red_behavior', 'green_acceptance', 'rollback', 'outcome_metric'] as $field) {
            $this->assertContains('missing_proposal_contract:'.$field, $result['rejection_reasons']);
        }
    }

    public function test_missing_objective_is_rejected(): void
    {
        $result = $this->bridge()->bridge($this->validProposal(['objective' => '']));

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing_objective', $result['rejection_reasons']);
    }

    // ── multiple simultaneous rejection reasons ──────────────────────────────

    public function test_multiple_failures_report_all_reasons(): void
    {
        $result = $this->bridge()->bridge([
            'objective'           => '',
            'allowed_files'       => [],
            'acceptance_criteria' => [],
            'required_evidence'   => [],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing_objective', $result['rejection_reasons']);
        $this->assertContains('insufficient_allowed_files', $result['rejection_reasons']);
        $this->assertContains('non_runnable_acceptance', $result['rejection_reasons']);
        $this->assertContains('missing_required_evidence', $result['rejection_reasons']);
        $this->assertContains('missing_leverage_reason', $result['rejection_reasons']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $bridge = $this->bridge();
        $proposal = $this->validProposal();

        $this->assertSame($bridge->bridge($proposal), $bridge->bridge($proposal));
    }
}
