<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSingleSessionInstructionPacketContractService;
use Tests\TestCase;

/**
 * Pins the Single Session Instruction Packet Contract:
 *  - the doc's exact "Purpose / It must include" field list (instruction packet);
 *  - the doc's exact "Codex Start Contract" field list (stronger superset);
 *  - "self-contained" means every field present with real content;
 *  - every "Non Goal" (dispatch / auto-start / auto-merge / grant execution
 *    authority / mark completion / hide blockers) is a hard STOP CONDITION that
 *    rejects the packet no matter how complete it is;
 *  - dispatch/auto-merge/completion always stay separate governed steps;
 *  - the validator never authorizes runtime and never dispatches.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/single-session-instruction-packet-contract.md
 */
class AtlasSingleSessionInstructionPacketContractTest extends TestCase
{
    private function service(): AtlasSingleSessionInstructionPacketContractService
    {
        return new AtlasSingleSessionInstructionPacketContractService;
    }

    public function test_required_field_lists_match_the_documented_contract(): void
    {
        $fields = $this->service()->requiredFields();

        // "Purpose / It must include" — the 10 instruction-packet fields.
        $this->assertSame([
            'selected_packet_id',
            'operator_instruction',
            'required_first_commands',
            'allowed_files',
            'forbidden_hot_scopes',
            'required_gates',
            'evidence_expected',
            'stop_conditions',
            'bootstrap_hash',
            'readiness_hash',
        ], $fields['instruction']);

        // The Codex Start Contract is a strict superset (durable claim + the
        // explicit command set the doc enumerates).
        $this->assertGreaterThan(count($fields['instruction']), count($fields['codex_start']));
        foreach (['claimed_packet_id', 'reservation_id', 'lease_expiry', 'claim_hash', 'bootstrap_command', 'scope_validator_command', 'completion_command', 'release_command'] as $startOnly) {
            $this->assertContains($startOnly, $fields['codex_start']);
        }
    }

    public function test_complete_instruction_packet_with_no_non_goals_is_accepted(): void
    {
        $service = $this->service();
        $result = $service->validate($service->sampleInstructionPacket('packet-1'), AtlasSingleSessionInstructionPacketContractService::KIND_INSTRUCTION);

        $this->assertSame('accept', $result['verdict']);
        $this->assertTrue($result['accepted']);
        $this->assertTrue($result['self_contained']);
        $this->assertSame([], $result['missing_fields']);
        $this->assertSame([], $result['prohibition_violations']);
        $this->assertSame(10, $result['required_field_count']);
        $this->assertSame(10, $result['present_field_count']);
        // Read-only invariants hold on accept too.
        $this->assertFalse($result['runtime_authorized']);
        $this->assertFalse($result['dispatched']);
        $this->assertTrue($result['dispatch_remains_separate']);
    }

    public function test_missing_or_empty_required_field_makes_packet_not_self_contained_and_rejects(): void
    {
        $service = $this->service();

        // Drop one field entirely.
        $dropped = $service->sampleInstructionPacket('packet-2');
        unset($dropped['stop_conditions']);
        $r1 = $service->validate($dropped);
        $this->assertSame('reject', $r1['verdict']);
        $this->assertFalse($r1['self_contained']);
        $this->assertContains('stop_conditions', $r1['missing_fields']);
        $this->assertContains('missing_required_field:stop_conditions', $r1['reasons']);

        // Present-but-empty does NOT count as a self-contained field.
        $blank = $service->sampleInstructionPacket('packet-3');
        $blank['operator_instruction'] = '';   // blank string
        $blank['allowed_files'] = [];           // empty array
        $r2 = $service->validate($blank);
        $this->assertSame('reject', $r2['verdict']);
        $this->assertContains('operator_instruction', $r2['missing_fields']);
        $this->assertContains('allowed_files', $r2['missing_fields']);
    }

    public function test_every_non_goal_flag_is_a_hard_stop_condition_even_when_fields_are_complete(): void
    {
        $service = $this->service();

        $nonGoals = [
            'dispatches_work' => 'non_goal_dispatch_work',
            'auto_starts_process' => 'non_goal_auto_start_process',
            'auto_merges' => 'non_goal_auto_merge',
            'grants_execution_authority' => 'non_goal_grant_execution_authority',
            'marks_completion' => 'non_goal_mark_completion',
            'hides_blockers' => 'non_goal_hide_blockers',
        ];

        foreach ($nonGoals as $flag => $code) {
            $packet = $service->sampleInstructionPacket('packet-nongoal');
            // Fields stay fully complete; only the Non-Goal is declared.
            $packet[$flag] = true;

            $result = $service->validate($packet);

            $this->assertTrue($result['self_contained'], "fields complete for {$flag}");
            $this->assertSame('reject', $result['verdict'], "Non-Goal {$flag} must reject");
            $this->assertFalse($result['accepted']);
            $this->assertContains($code, $result['prohibition_violations']);
            $this->assertContains($code, $result['reasons']);
        }

        // The closed prohibition set is exactly these six codes.
        $this->assertSame(array_values($nonGoals), $service->prohibitionCodes());
    }

    public function test_codex_start_packet_requires_the_full_start_contract_superset(): void
    {
        $service = $this->service();

        // An instruction-shaped packet is NOT a valid Codex Start Packet: it is
        // missing the durable claim + command fields the start contract demands.
        $instructionShaped = $service->sampleInstructionPacket('packet-start');
        $asStart = $service->validate($instructionShaped, AtlasSingleSessionInstructionPacketContractService::KIND_CODEX_START);

        $this->assertSame('reject', $asStart['verdict']);
        $this->assertContains('claimed_packet_id', $asStart['missing_fields']);
        $this->assertContains('reservation_id', $asStart['missing_fields']);
        $this->assertContains('lease_expiry', $asStart['missing_fields']);
        $this->assertContains('completion_command', $asStart['missing_fields']);
        $this->assertContains('release_command', $asStart['missing_fields']);
        $this->assertSame(20, $asStart['required_field_count']);
    }

    public function test_describe_runs_a_live_accept_and_reject_pair_without_authorizing_runtime(): void
    {
        $payload = $this->service()->describe();

        $this->assertSame('accept', $payload['sample_accept']['verdict']);
        $this->assertSame('reject', $payload['sample_reject']['verdict']);
        // The reject sample both drops a field and declares a Non-Goal.
        $this->assertContains('non_goal_auto_merge', $payload['sample_reject']['prohibition_violations']);
        $this->assertContains('stop_conditions', $payload['sample_reject']['missing_fields']);
        $this->assertSame(6, $payload['prohibition_count']);
        $this->assertTrue($payload['dispatch_remains_separate']);
        $this->assertFalse($payload['runtime_authorized']);
        $this->assertFalse($payload['dispatched']);
    }
}
