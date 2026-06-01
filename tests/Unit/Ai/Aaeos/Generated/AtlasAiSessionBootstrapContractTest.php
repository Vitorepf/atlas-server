<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiSessionBootstrapContractService;
use Tests\TestCase;

/**
 * Pins the Atlas Self-Construction AI Session Bootstrap Contract:
 *  - the doc's exact "Payload Schema" field list;
 *  - the authority invariants: execution_allowed and ledger_write_allowed must
 *    always be false (in every mode);
 *  - the provider-adapter invariant: adapter_may_widen_scope must ALWAYS be
 *    false;
 *  - the mode-specific claim invariant: read_only must NOT persist a claim,
 *    claim_next / codex_start MUST persist one;
 *  - codex_start must additionally carry a completion_command;
 *  - every "Non Goal" (dispatch session / grant execution authority / mark
 *    completion / hide hot blockers) is a hard STOP CONDITION;
 *  - the doc's closed "Stop Conditions" set blocks the bootstrap when active;
 *  - the five "Required First Commands", packet-scoped when a packet is claimed;
 *  - the validator never authorizes execution, writes the ledger or dispatches.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md
 */
class AtlasAiSessionBootstrapContractTest extends TestCase
{
    private function service(): AtlasAiSessionBootstrapContractService
    {
        return new AtlasAiSessionBootstrapContractService;
    }

    public function test_required_fields_and_stop_conditions_match_the_documented_contract(): void
    {
        $service = $this->service();

        // "Payload Schema" — the 11 documented fields, in doc order.
        $this->assertSame([
            'bootstrap_id',
            'session_mode',
            'selected_packet_id',
            'assignment_hash',
            'reservation_hash',
            'runbook_hash',
            'scope_validator_status',
            'completion_gate_status',
            'execution_allowed',
            'claim_persisted',
            'ledger_write_allowed',
        ], $service->requiredFields());

        // "Stop Conditions" — the seven documented conditions.
        $this->assertSame([
            'selected_packet_missing',
            'hash_changed_unexpectedly',
            'scope_blocked_by_other_lane',
            'hot_voice_or_kernel_in_scope',
            'required_gates_failed',
            'operator_evidence_missing',
            'completion_gate_blocked',
        ], array_keys($service->stopConditions()));

        // "Non Goals" — the four documented prohibition codes.
        $this->assertSame([
            'non_goal_dispatch_session',
            'non_goal_grant_execution_authority',
            'non_goal_mark_completion',
            'non_goal_hide_hot_blockers',
        ], $service->nonGoalCodes());
    }

    public function test_complete_read_only_payload_with_no_violations_is_accepted(): void
    {
        $service = $this->service();
        $result = $service->validate($service->sampleReadOnlyPayload('AIP-SPLIT-7'), AtlasAiSessionBootstrapContractService::BOOTSTRAP_READ_ONLY);

        $this->assertSame('accept', $result['verdict']);
        $this->assertTrue($result['accepted']);
        $this->assertTrue($result['self_contained']);
        $this->assertSame([], $result['missing_fields']);
        $this->assertSame([], $result['authority_violations']);
        $this->assertSame([], $result['non_goal_violations']);
        $this->assertSame([], $result['active_stop_conditions']);
        $this->assertSame(11, $result['required_field_count']);
        $this->assertSame(11, $result['present_field_count']);
        // Read-only authority invariants always hold, even on accept.
        $this->assertFalse($result['execution_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['dispatched']);
        $this->assertFalse($result['claim_persisted']);
    }

    public function test_execution_or_ledger_write_authority_being_granted_blocks_the_bootstrap(): void
    {
        $service = $this->service();

        // execution_allowed=true violates the authority invariant.
        $exec = $service->sampleReadOnlyPayload();
        $exec['execution_allowed'] = true;
        $r1 = $service->validate($exec);
        $this->assertSame('blocked', $r1['verdict']);
        $this->assertContains('execution_allowed_must_be_false', $r1['authority_violations']);
        // The reported invariant stays false no matter what the payload claimed.
        $this->assertFalse($r1['execution_allowed']);

        // ledger_write_allowed=true violates the authority invariant.
        $ledger = $service->sampleReadOnlyPayload();
        $ledger['ledger_write_allowed'] = true;
        $r2 = $service->validate($ledger);
        $this->assertSame('blocked', $r2['verdict']);
        $this->assertContains('ledger_write_allowed_must_be_false', $r2['authority_violations']);
        $this->assertFalse($r2['ledger_write_allowed']);
    }

    public function test_adapter_may_widen_scope_true_always_blocks(): void
    {
        $service = $this->service();

        $payload = $service->sampleReadOnlyPayload();
        // Everything else complete and clean; only the adapter flag is flipped.
        $payload['adapter_may_widen_scope'] = true;

        $result = $service->validate($payload);

        $this->assertTrue($result['self_contained'], 'fields stay complete');
        $this->assertSame('blocked', $result['verdict']);
        $this->assertTrue($result['adapter_scope_violation']);
        $this->assertContains('adapter_may_widen_scope_must_be_false', $result['reasons']);
    }

    public function test_every_non_goal_and_stop_condition_blocks_even_with_complete_fields(): void
    {
        $service = $this->service();

        // Each Non-Goal alone must block, fields fully complete.
        $nonGoals = [
            'dispatches_session' => 'non_goal_dispatch_session',
            'grants_execution_authority' => 'non_goal_grant_execution_authority',
            'marks_completion' => 'non_goal_mark_completion',
            'hides_hot_blockers' => 'non_goal_hide_hot_blockers',
        ];
        foreach ($nonGoals as $flag => $code) {
            $payload = $service->sampleReadOnlyPayload();
            $payload[$flag] = true;
            $result = $service->validate($payload);

            $this->assertTrue($result['self_contained'], "fields complete for {$flag}");
            $this->assertSame('blocked', $result['verdict'], "Non-Goal {$flag} must block");
            $this->assertContains($code, $result['non_goal_violations']);
        }

        // Each documented stop condition, reported active, must block.
        foreach (array_keys($service->stopConditions()) as $key) {
            $payload = $service->sampleReadOnlyPayload();
            $payload['stop_'.$key] = true;
            $result = $service->validate($payload);

            $this->assertSame('blocked', $result['verdict'], "stop condition {$key} must block");
            $this->assertContains($key, $result['active_stop_conditions']);
            $this->assertContains('stop_condition:'.$key, $result['reasons']);
        }
    }

    public function test_claim_invariant_differs_by_mode_and_codex_start_needs_completion_command(): void
    {
        $service = $this->service();

        // read_only must NOT persist a claim: a read-only payload that does is blocked.
        $readOnlyWithClaim = $service->sampleReadOnlyPayload();
        $readOnlyWithClaim['claim_persisted'] = true;
        $r1 = $service->validate($readOnlyWithClaim, AtlasAiSessionBootstrapContractService::BOOTSTRAP_READ_ONLY);
        $this->assertSame('blocked', $r1['verdict']);
        $this->assertContains('read_only_must_not_persist_claim', $r1['reasons']);

        // claim_next must persist a claim: the read-only sample (claim false) is blocked under claim_next.
        $r2 = $service->validate($service->sampleReadOnlyPayload(), AtlasAiSessionBootstrapContractService::BOOTSTRAP_CLAIM_NEXT);
        $this->assertSame('blocked', $r2['verdict']);
        $this->assertContains('claim_mode_must_persist_claim', $r2['reasons']);

        // A proper codex_start payload (claim persisted + completion_command) is accepted.
        $start = $service->validate($service->sampleCodexStartPayload('AIP-SPLIT-9'), AtlasAiSessionBootstrapContractService::BOOTSTRAP_CODEX_START);
        $this->assertSame('accept', $start['verdict']);
        $this->assertTrue($start['claim_persisted']);
        $this->assertSame(12, $start['required_field_count']); // 11 + completion_command

        // codex_start WITHOUT a completion_command is blocked (doc: "must also include a completion command").
        $startNoCompletion = $service->sampleCodexStartPayload('AIP-SPLIT-9');
        unset($startNoCompletion['completion_command']);
        $r3 = $service->validate($startNoCompletion, AtlasAiSessionBootstrapContractService::BOOTSTRAP_CODEX_START);
        $this->assertSame('blocked', $r3['verdict']);
        $this->assertContains('completion_command', $r3['missing_fields']);
    }

    public function test_required_first_commands_are_the_documented_five_and_packet_scope_when_claimed(): void
    {
        $service = $this->service();

        $unscoped = $service->requiredFirstCommands();
        $this->assertCount(5, $unscoped);
        $this->assertSame('git status --short', $unscoped[0]);
        $this->assertSame('git diff --stat', $unscoped[1]);
        $this->assertSame('git diff --name-only', $unscoped[2]);
        $this->assertStringContainsString('--ai-session-bootstrap', $unscoped[3]);
        $this->assertStringContainsString('--scope-validator', $unscoped[4]);
        // Unscoped commands carry no --packet.
        $this->assertStringNotContainsString('--packet=', $unscoped[3]);

        // For a durable claimed packet, the packet-scoped commands carry --packet=<id>.
        $scoped = $service->requiredFirstCommands('AIP-SPLIT-42');
        $this->assertStringContainsString('--packet=AIP-SPLIT-42', $scoped[3]);
        $this->assertStringContainsString('--packet=AIP-SPLIT-42', $scoped[4]);
    }

    public function test_describe_runs_a_live_accept_and_blocked_pair_without_authorizing_execution(): void
    {
        $payload = $this->service()->describe();

        $this->assertSame('accept', $payload['sample_accept']['verdict']);
        $this->assertSame('blocked', $payload['sample_blocked']['verdict']);
        // The blocked sample drops a field, declares a Non-Goal, and widens scope.
        $this->assertContains('runbook_hash', $payload['sample_blocked']['missing_fields']);
        $this->assertContains('non_goal_mark_completion', $payload['sample_blocked']['non_goal_violations']);
        $this->assertTrue($payload['sample_blocked']['adapter_scope_violation']);
        $this->assertSame(4, $payload['non_goal_count']);
        $this->assertSame(7, $payload['stop_condition_count']);
        $this->assertFalse($payload['adapter_may_widen_scope_invariant']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['ledger_write_allowed']);
        $this->assertFalse($payload['dispatched']);
    }
}
