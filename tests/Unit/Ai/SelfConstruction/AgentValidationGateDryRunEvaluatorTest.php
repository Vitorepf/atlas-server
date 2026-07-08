<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Support\AgentValidationGateDryRunEvaluator;
use PHPUnit\Framework\TestCase;

final class AgentValidationGateDryRunEvaluatorTest extends TestCase
{
    private function evaluator(): AgentValidationGateDryRunEvaluator
    {
        return new AgentValidationGateDryRunEvaluator;
    }

    private function plan(array $runs): array
    {
        return ['plan_id' => 'plan-1', 'plan_hash' => 'hash-1', 'ordered_runs' => $runs];
    }

    public function test_blocking_failure_records_abort_trace_with_aborted_gate_and_skipped_downstream_ids(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => true],
            ['gate_id' => 'gate_b', 'blocking' => false],
            ['gate_id' => 'gate_c', 'blocking' => false],
        ]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'fail', 'evidence_artifact' => 'artifact'],
        ]);

        $this->assertTrue($result['aborted']);
        $this->assertSame('gate_a', $result['abort_trace']['aborted_at_gate']);
        $this->assertSame(['gate_b', 'gate_c'], $result['abort_trace']['skipped_gate_ids']);
    }

    public function test_unsupported_synthetic_status_reports_unsupported_reason_and_counts_as_unknown(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => false],
        ]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'not_a_real_status', 'evidence_artifact' => 'artifact'],
        ]);

        $this->assertSame('unsupported_synthetic_status', $result['evaluations'][0]['observed_reason']);
        $this->assertSame(1, $result['counts']['unknown']);
    }

    public function test_missing_synthetic_input_on_blocking_gate_aborts_downstream_gates(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => true],
            ['gate_id' => 'gate_b', 'blocking' => false],
        ]);
        $result = $this->evaluator()->evaluate($plan, []);

        $this->assertSame('no_synthetic_input', $result['evaluations'][0]['observed_reason']);
        $this->assertSame('previous_blocking_gate_failed', $result['evaluations'][1]['observed_reason']);
        $this->assertTrue($result['aborted']);
        $this->assertSame(['gate_b'], $result['abort_trace']['skipped_gate_ids']);
    }

    public function test_no_abort_yields_null_abort_trace(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => false],
        ]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'pass', 'evidence_artifact' => 'artifact'],
        ]);

        $this->assertFalse($result['aborted']);
        $this->assertNull($result['abort_trace']);
    }

    // ── AC: per-gate result includes blockers, warnings, required_inputs, safe_to_execute ──

    public function test_evaluate_includes_blockers_warnings_required_inputs_safe_to_execute(): void
    {
        $plan = $this->plan([['gate_id' => 'gate_a', 'blocking' => true]]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'pass', 'evidence_artifact' => 'artifact'],
        ]);

        foreach (['blockers', 'warnings', 'required_inputs', 'safe_to_execute'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_safe_to_execute_true_when_all_pass(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => true],
            ['gate_id' => 'gate_b', 'blocking' => false],
        ]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'pass', 'evidence_artifact' => 'a'],
            'gate_b' => ['status' => 'pass', 'evidence_artifact' => 'b'],
        ]);

        $this->assertTrue($result['safe_to_execute']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_safe_to_execute_false_when_gate_fails(): void
    {
        $plan = $this->plan([['gate_id' => 'gate_a', 'blocking' => true]]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'fail', 'evidence_artifact' => 'a'],
        ]);

        $this->assertFalse($result['safe_to_execute']);
        $this->assertNotEmpty($result['blockers']);
    }

    public function test_safe_to_execute_false_when_input_missing(): void
    {
        $plan = $this->plan([['gate_id' => 'gate_a', 'blocking' => true]]);
        $result = $this->evaluator()->evaluate($plan, []);

        $this->assertFalse($result['safe_to_execute']);
        $this->assertNotEmpty($result['blockers']);
    }

    public function test_required_inputs_listed_for_each_gate(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => true, 'expected_artifact' => 'test_result'],
            ['gate_id' => 'gate_b', 'blocking' => false, 'expected_artifact' => 'lint_result'],
        ]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'pass'],
            'gate_b' => ['status' => 'pass'],
        ]);

        $this->assertCount(2, $result['required_inputs']);
        $this->assertSame('gate_a', $result['required_inputs'][0]['gate_id']);
        $this->assertTrue($result['required_inputs'][0]['required']);
        $this->assertFalse($result['required_inputs'][1]['required']);
    }

    // ── AC: rejects destructive commands ─────────────────────────────────────

    public function test_evaluate_blocks_destructive_git_reset(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => false, 'commands' => ['git reset --hard HEAD~1']],
        ]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'pass'],
        ]);

        $found = false;
        foreach ($result['blockers'] as $blocker) {
            if ($blocker['reason'] === 'destructive_command_detected') {
                $found = true;
            }
        }
        $this->assertTrue($found);
        $this->assertFalse($result['safe_to_execute']);
    }

    public function test_evaluate_blocks_force_push(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => false, 'commands' => ['git push --force origin main']],
        ]);
        $result = $this->evaluator()->evaluate($plan, ['gate_a' => ['status' => 'pass']]);

        $found = false;
        foreach ($result['blockers'] as $b) {
            if ($b['reason'] === 'destructive_command_detected') {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function test_evaluate_blocks_rm_rf(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => false, 'commands' => ['rm -rf /tmp/important']],
        ]);
        $result = $this->evaluator()->evaluate($plan, ['gate_a' => ['status' => 'pass']]);

        $found = false;
        foreach ($result['blockers'] as $b) {
            if ($b['reason'] === 'destructive_command_detected') {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    // ── AC: rejects broad file writes ────────────────────────────────────────

    public function test_evaluate_blocks_broad_wildcard_write(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => false, 'write_scope' => ['*']],
        ]);
        $result = $this->evaluator()->evaluate($plan, ['gate_a' => ['status' => 'pass']]);

        $found = false;
        foreach ($result['blockers'] as $b) {
            if ($b['reason'] === 'broad_file_write_detected') {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    // ── AC: rejects provider-only checks ─────────────────────────────────────

    public function test_evaluate_blocks_provider_only_gate(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => false, 'gate_type' => 'provider_only'],
        ]);
        $result = $this->evaluator()->evaluate($plan, ['gate_a' => ['status' => 'pass']]);

        $found = false;
        foreach ($result['blockers'] as $b) {
            if ($b['reason'] === 'provider_only_check_not_dry_runnable') {
                $found = true;
            }
        }
        $this->assertTrue($found);
        $this->assertFalse($result['safe_to_execute']);
    }

    // ── AC: marks gates failed when inputs malformed/stale ──────────────────

    public function test_evaluate_marks_gate_unknown_for_malformed_status(): void
    {
        $plan = $this->plan([['gate_id' => 'gate_a', 'blocking' => true]]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'garbage', 'evidence_artifact' => 'x'],
        ]);

        $this->assertSame('unknown', $result['evaluations'][0]['observed_status']);
        $this->assertFalse($result['safe_to_execute']);
    }

    // ── warnings ─────────────────────────────────────────────────────────────

    public function test_warnings_collected_for_warn_status(): void
    {
        $plan = $this->plan([['gate_id' => 'gate_a', 'blocking' => false]]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'warn', 'evidence_artifact' => 'x'],
        ]);

        $this->assertNotEmpty($result['warnings']);
        $this->assertSame('gate_emitted_warning', $result['warnings'][0]['reason']);
    }

    public function test_safe_to_execute_true_with_only_warnings(): void
    {
        $plan = $this->plan([['gate_id' => 'gate_a', 'blocking' => false]]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'warn', 'evidence_artifact' => 'x'],
        ]);

        // Warnings don't block — safe to execute is still true if no blockers
        $this->assertTrue($result['safe_to_execute']);
        $this->assertSame([], $result['blockers']);
    }

    // ── determinism ─────────────────────────────────────────────────────────

    public function test_evaluation_hash_is_hex64(): void
    {
        $plan = $this->plan([['gate_id' => 'gate_a', 'blocking' => false]]);
        $result = $this->evaluator()->evaluate($plan, ['gate_a' => ['status' => 'pass']]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['evaluation_hash']);
    }

    public function test_evaluation_deterministic(): void
    {
        $plan = $this->plan([['gate_id' => 'gate_a', 'blocking' => false]]);
        $inputs = ['gate_a' => ['status' => 'pass']];

        $a = $this->evaluator()->evaluate($plan, $inputs);
        $b = $this->evaluator()->evaluate($plan, $inputs);

        $this->assertSame($a['evaluation_hash'], $b['evaluation_hash']);
    }
}
