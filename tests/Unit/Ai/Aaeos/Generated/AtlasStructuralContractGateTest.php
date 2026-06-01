<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasStructuralContractGateService;
use Tests\TestCase;

/**
 * Pins the documented Structural Contract Gate rules: mandatory order, the
 * minimum contract checklist, runtime unlock criteria and the one-line
 * multi-agent lock.
 *
 * @see docs/engineering-knowledge-base/self-construction/structural-contract-gate.md
 */
class AtlasStructuralContractGateTest extends TestCase
{
    private function service(): AtlasStructuralContractGateService
    {
        return new AtlasStructuralContractGateService();
    }

    /** Every checklist field, used to model a complete contract document. */
    private function fullContract(): array
    {
        return AtlasStructuralContractGateService::CONTRACT_CHECKLIST;
    }

    /** All six runtime-unlock signals satisfied. */
    private function fullUnlock(): array
    {
        return AtlasStructuralContractGateService::UNLOCK_CRITERIA;
    }

    /**
     * Rule: "runtime implementation is forbidden until a complete contract
     * document exists." Asking for read-only runtime (step 8) on a structural
     * subsystem with an EMPTY contract must block, and must NOT permit code.
     */
    public function test_read_only_runtime_blocked_when_contract_is_empty(): void
    {
        $v = $this->service()->evaluate([
            'subsystem' => 'scope_validator',
            'requested_step' => AtlasStructuralContractGateService::FIRST_CODE_STEP, // 8
            'contract' => [],
            'unlock' => [],
        ]);

        $this->assertSame(AtlasStructuralContractGateService::VERDICT_BLOCK, $v['verdict']);
        $this->assertFalse($v['runtime_code_permitted']);
        $this->assertFalse($v['contract_checklist_complete']);
        // The allowed step can never reach a code step (8) with no contract.
        $this->assertLessThan(AtlasStructuralContractGateService::FIRST_CODE_STEP, $v['allowed_step']);
        // It reports the missing contract as the blocker, not a vague refusal.
        $this->assertContains('contract_incomplete:purpose_and_non_goals', $v['blocking']);
        $this->assertSame('stop_and_complete_contract_first', $v['required_next_action']);
    }

    /**
     * Mandatory Order: a complete contract unlocks the "define" steps (1..7) and,
     * once ALL six unlock criteria also hold, read-only runtime (step 8) — but
     * NOT scoped execution (step 9), which additionally needs runtime+validation.
     */
    public function test_full_contract_and_unlock_allows_read_only_runtime_but_not_scoped_execution(): void
    {
        $v = $this->service()->evaluate([
            'subsystem' => 'scope_validator',
            'requested_step' => AtlasStructuralContractGateService::FIRST_CODE_STEP, // 8
            'contract' => $this->fullContract(),
            'unlock' => $this->fullUnlock(),
        ]);

        $this->assertSame(AtlasStructuralContractGateService::VERDICT_ALLOW, $v['verdict']);
        $this->assertTrue($v['runtime_code_permitted']);
        $this->assertSame(AtlasStructuralContractGateService::FIRST_CODE_STEP, $v['allowed_step']); // exactly 8, NOT 9
        $this->assertTrue($v['contract_checklist_complete']);
        $this->assertTrue($v['runtime_unlock_satisfied']);
        $this->assertSame([], $v['blocking']);
    }

    /**
     * "Only after validation consider scoped execution." Step 9 stays blocked
     * even with a full contract + unlock, until read-only runtime exists AND
     * validation has passed.
     */
    public function test_scoped_execution_requires_runtime_and_validation(): void
    {
        $service = $this->service();

        $blocked = $service->evaluate([
            'subsystem' => 'self_construction_runtime',
            'requested_step' => AtlasStructuralContractGateService::SCOPED_EXECUTION_STEP, // 9
            'contract' => $this->fullContract(),
            'unlock' => $this->fullUnlock(),
            'read_only_runtime_implemented' => false,
            'validation_passed' => false,
        ]);
        $this->assertSame(AtlasStructuralContractGateService::VERDICT_BLOCK, $blocked['verdict']);
        $this->assertContains('read_only_runtime_not_implemented', $blocked['blocking']);
        $this->assertContains('validation_not_passed', $blocked['blocking']);

        $allowed = $service->evaluate([
            'subsystem' => 'self_construction_runtime',
            'requested_step' => AtlasStructuralContractGateService::SCOPED_EXECUTION_STEP, // 9
            'contract' => $this->fullContract(),
            'unlock' => $this->fullUnlock(),
            'read_only_runtime_implemented' => true,
            'validation_passed' => true,
        ]);
        $this->assertSame(AtlasStructuralContractGateService::VERDICT_ALLOW, $allowed['verdict']);
        $this->assertSame(AtlasStructuralContractGateService::SCOPED_EXECUTION_STEP, $allowed['allowed_step']);
    }

    /**
     * Runtime Unlock Criteria are ALL-required: a complete contract but ONE
     * missing unlock signal still blocks read-only runtime, and names that gap.
     */
    public function test_one_missing_unlock_signal_blocks_read_only_runtime(): void
    {
        $unlockMinusDocsHealth = array_values(array_filter(
            AtlasStructuralContractGateService::UNLOCK_CRITERIA,
            static fn (string $c): bool => $c !== 'docs_health_clean',
        ));

        $v = $this->service()->evaluate([
            'subsystem' => 'memory_os',
            'requested_step' => AtlasStructuralContractGateService::FIRST_CODE_STEP, // 8
            'contract' => $this->fullContract(),
            'unlock' => $unlockMinusDocsHealth,
        ]);

        $this->assertSame(AtlasStructuralContractGateService::VERDICT_BLOCK, $v['verdict']);
        $this->assertFalse($v['runtime_unlock_satisfied']);
        $this->assertSame(['docs_health_clean'], $v['runtime_unlock_gaps']);
        $this->assertContains('unlock_criteria_unmet:docs_health_clean', $v['blocking']);
    }

    /**
     * Non-Negotiable Invariant scope: a NON-structural target is not governed by
     * this gate. Even a code step is admitted, with the full order available.
     */
    public function test_non_structural_target_is_not_gated(): void
    {
        $v = $this->service()->evaluate([
            'subsystem' => 'local_low_risk_slice',
            'requested_step' => AtlasStructuralContractGateService::FIRST_CODE_STEP,
            'contract' => [],
            'unlock' => [],
        ]);

        $this->assertSame(AtlasStructuralContractGateService::VERDICT_ALLOW, $v['verdict']);
        $this->assertFalse($v['is_structural']);
        $this->assertSame(AtlasStructuralContractGateService::SCOPED_EXECUTION_STEP, $v['allowed_step']);
        $this->assertSame([], $v['blocking']);
    }

    /**
     * One-Line Multi-Agent Goal lock: forbidden until packet, work splitter AND
     * scope validator each have a complete contract and read-only validation.
     */
    public function test_one_line_multi_agent_goal_locked_until_all_three_fronts_ready(): void
    {
        $service = $this->service();

        // All three contracts complete, but validation missing for one front.
        $partial = $service->oneLineMultiAgentReadiness([
            'packet_fields' => AtlasStructuralContractGateService::PACKET_GATE_FIELDS,
            'splitter_fields' => AtlasStructuralContractGateService::SPLITTER_GATE_FIELDS,
            'validator_fields' => AtlasStructuralContractGateService::VALIDATOR_GATE_FIELDS,
            'read_only_validation' => [
                'packet' => true,
                'work_splitter' => true,
                'scope_validator' => false,
            ],
        ]);
        $this->assertFalse($partial['allowed']);
        $this->assertContains('read_only_validation_missing:scope_validator', $partial['blocking']);

        // Everything documented AND validated => the experience is unlocked.
        $ready = $service->oneLineMultiAgentReadiness([
            'packet_fields' => AtlasStructuralContractGateService::PACKET_GATE_FIELDS,
            'splitter_fields' => AtlasStructuralContractGateService::SPLITTER_GATE_FIELDS,
            'validator_fields' => AtlasStructuralContractGateService::VALIDATOR_GATE_FIELDS,
            'read_only_validation' => [
                'packet' => true,
                'work_splitter' => true,
                'scope_validator' => true,
            ],
        ]);
        $this->assertTrue($ready['allowed']);
        $this->assertSame([], $ready['blocking']);
    }
}
