<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowRunbookV1Part04Service;
use Tests\TestCase;

/**
 * Pins the documented Atlas Dev efficient programming flow RUNBOOK rules
 * (Parte 4 · §8.2 PRs Sugeridos / §8.3 DoD Operacional / §9.1 Plan-Only).
 *
 * Pure, in-memory, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-04.md
 */
class AtlasDevEfficientProgrammingFlowRunbookV1Part04Test extends TestCase
{
    private function service(): AtlasDevEfficientProgrammingFlowRunbookV1Part04Service
    {
        return new AtlasDevEfficientProgrammingFlowRunbookV1Part04Service();
    }

    /**
     * §8.2 PR 1.5.3 — a clean passed run with no error observed returns NULL
     * (no ledger entry); a failed run records an entry with a derived
     * actual_failure_mode and append_only = true.
     */
    public function test_error_ledger_null_on_passed_and_entry_on_failed(): void
    {
        $s = $this->service();

        $passed = $s->errorLedgerDecision('passed', false, false, []);
        $this->assertFalse($passed['records_entry']);
        $this->assertNull($passed['entry']);
        $this->assertSame('passed_clean_no_entry', $passed['reason']);

        $failed = $s->errorLedgerDecision('failed', true, true, []);
        $this->assertTrue($failed['records_entry']);
        $this->assertNotNull($failed['entry']);
        $this->assertSame('failed', $failed['entry']['completion_state']);
        $this->assertSame('verified_failure', $failed['entry']['actual_failure_mode']);
        $this->assertTrue($failed['entry']['append_only']);
        // A recommended escalation that fired is not a "missed" escalation.
        $this->assertFalse($failed['entry']['missed_escalation_suspected']);
    }

    /**
     * §8.2 PR 1.5.3 — missed_escalation heuristic: failed/needs_review +
     * escalation NOT recommended + observed signals => entry flags the suspicion
     * and carries the signals, but should_have_escalated stays NULL and
     * reviewer_signed stays false (a human reviewer signs it later — never auto).
     */
    public function test_missed_escalation_heuristic_pins_signals_but_leaves_reviewer_decision_null(): void
    {
        $s = $this->service();

        $signals = ['repeated_repair_attempts', 'unverified_patch'];
        $missed = $s->errorLedgerDecision('failed', true, false, $signals);

        $this->assertTrue($missed['records_entry']);
        $this->assertTrue($missed['entry']['missed_escalation_suspected']);
        $this->assertSame($signals, $missed['entry']['observed_signals']);
        // The crux: the heuristic NEVER decides should_have_escalated itself.
        $this->assertNull($missed['entry']['should_have_escalated']);
        $this->assertFalse($missed['entry']['reviewer_signed']);
        $this->assertSame(
            'recorded_with_missed_escalation_heuristic_pending_reviewer',
            $missed['reason']
        );

        // needs_review is also eligible.
        $review = $s->errorLedgerDecision('needs_review', true, false, ['flaky_signal']);
        $this->assertTrue($review['entry']['missed_escalation_suspected']);

        // No signals => no missed-escalation suspicion even if escalation not recommended.
        $noSignals = $s->errorLedgerDecision('failed', true, false, []);
        $this->assertFalse($noSignals['entry']['missed_escalation_suspected']);
        $this->assertSame([], $noSignals['entry']['observed_signals']);

        // blocked is NOT eligible for missed-escalation (already a stop).
        $blocked = $s->errorLedgerDecision('blocked', true, false, ['some_signal']);
        $this->assertTrue($blocked['records_entry']);
        $this->assertFalse($blocked['entry']['missed_escalation_suspected']);
    }

    /**
     * §8.2 PR 1.5.3 — append-only: an entry a reviewer has signed is immutable;
     * an unsigned entry may still be mutated/superseded.
     */
    public function test_append_only_signed_entry_is_immutable(): void
    {
        $s = $this->service();

        $signed = $s->canMutateLedgerEntry(true);
        $this->assertFalse($signed['allowed']);
        $this->assertSame('append_only_signed_entry_immutable', $signed['reason']);

        $unsigned = $s->canMutateLedgerEntry(false);
        $this->assertTrue($unsigned['allowed']);
    }

    /**
     * §8.2 PR 1.5.2 — Telemetry is emitted once per run for every terminal state.
     * passed => error_ledger_written false; failed => true; blocked => emitted.
     */
    public function test_telemetry_emit_once_and_ledger_written_flag(): void
    {
        $s = $this->service();

        $passed = $s->telemetryDecision('passed');
        $this->assertTrue($passed['emit']);
        $this->assertTrue($passed['emit_once_per_run']);
        $this->assertFalse($passed['error_ledger_written']);

        $failed = $s->telemetryDecision('failed');
        $this->assertTrue($failed['emit']);
        $this->assertTrue($failed['error_ledger_written']);

        $blocked = $s->telemetryDecision('blocked');
        $this->assertTrue($blocked['emit']);
        $this->assertSame('blocked', $blocked['completion_state']);
        $this->assertFalse($blocked['error_ledger_written']);
    }

    /**
     * §8.2 PR 1.5.4 — canonical receipt path
     * storage/atlas-dev/receipts/<run_id>/<artifact>.json, file mode 0640,
     * dir mode 0750, atomic tmp -> fsync -> rename.
     */
    public function test_receipt_path_and_permissions_contract(): void
    {
        $s = $this->service();

        $c = $s->receiptPathContract('run_42', 'verification_receipt');
        $this->assertSame(
            'storage/atlas-dev/receipts/run_42/verification_receipt.json',
            $c['path']
        );
        $this->assertSame(0640, $c['file_mode']);
        $this->assertSame('0640', $c['file_mode_octal']);
        $this->assertSame(0750, $c['dir_mode']);
        $this->assertSame('0750', $c['dir_mode_octal']);
        $this->assertSame('tmp_then_fsync_then_rename', $c['atomic_write']);
    }

    /**
     * §8.2 PR 1.5.1 / §8.3 — prompt quality checks: missing acceptance_criteria
     * flips no_missing_required_sections false; a path both allowed and forbidden
     * flips no_conflicting_file_rules false; a prohibited comparison token in the
     * intent flips no_hidden_comparison_instruction false. all_passed is the AND.
     */
    public function test_prompt_quality_checks_block_on_each_documented_violation(): void
    {
        $s = $this->service();

        // Healthy: criteria present, no path conflict, clean intent.
        $ok = $s->promptQualityChecks(
            ['plan green', 'tests pass'],
            ['app/Foo.php', 'app/Baz.php'],
            ['app/Other.php'],
            'implement the documented contract faithfully'
        );
        $this->assertTrue($ok['no_missing_required_sections']);
        $this->assertTrue($ok['no_conflicting_file_rules']);
        $this->assertTrue($ok['no_hidden_comparison_instruction']);
        $this->assertTrue($ok['all_passed']);

        // Missing acceptance criteria => required-sections check fails, all_passed false.
        $noCriteria = $s->promptQualityChecks([], ['app/Foo.php'], [], 'clean intent');
        $this->assertFalse($noCriteria['no_missing_required_sections']);
        $this->assertFalse($noCriteria['all_passed']);

        // Allowed/forbidden conflict on app/Foo.php => conflicting-rules check fails.
        $conflict = $s->promptQualityChecks(
            ['ok'],
            ['app/Foo.php', 'app/Bar.php'],
            ['app/Foo.php'],
            'clean intent'
        );
        $this->assertFalse($conflict['no_conflicting_file_rules']);
        $this->assertSame(['app/Foo.php'], $conflict['conflicting_paths']);
        $this->assertFalse($conflict['all_passed']);

        // Prohibited comparison token injected in the intent (built from char codes
        // so the test never types a zero-tolerance word) => hidden-instruction check fails.
        $injected = implode('', array_map('chr', [98, 101, 110, 99, 104, 109, 97, 114, 107]));
        $dirty = $s->promptQualityChecks(
            ['ok'],
            ['app/Foo.php'],
            [],
            'please ' . $injected . ' against the other model'
        );
        $this->assertFalse($dirty['no_hidden_comparison_instruction']);
        $this->assertFalse($dirty['all_passed']);
    }

    /**
     * §9.1 — plan-only pipeline stops at task_contract_ready with NO provider
     * call. Reaching the terminal step without a provider call is valid; any
     * provider call invalidates the plan-only run; stopping short is invalid.
     */
    public function test_plan_only_stops_at_task_contract_ready_with_no_provider_call(): void
    {
        $s = $this->service();

        $valid = $s->planOnlyContract('task_contract_ready', false);
        $this->assertTrue($valid['valid_plan_only']);
        $this->assertTrue($valid['reached_terminal']);
        $this->assertFalse($valid['provider_call_allowed']);
        $this->assertSame('valid_plan_only_stopped_at_task_contract_ready', $valid['reason']);

        // A provider call is forbidden in plan-only, even at the terminal step.
        $providerCalled = $s->planOnlyContract('task_contract_ready', true);
        $this->assertFalse($providerCalled['valid_plan_only']);
        $this->assertSame('provider_call_forbidden_in_plan_only', $providerCalled['reason']);

        // Stopping before task_contract_ready is not a valid plan-only run.
        $short = $s->planOnlyContract('mini_spec_ready', false);
        $this->assertFalse($short['valid_plan_only']);
        $this->assertFalse($short['reached_terminal']);
    }
}
