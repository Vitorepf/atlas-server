<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSpecOutcomeTraceJoiner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSpecOutcomeTraceJoinerTest extends TestCase
{
    private function joiner(): AtlasExternalBrainSpecOutcomeTraceJoiner
    {
        return new AtlasExternalBrainSpecOutcomeTraceJoiner;
    }

    private function spec(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'task-1',
            'task_family' => 'cortex',
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['phpunit passes'],
            'worker' => 'claude-muscle-1',
            'model' => 'sonnet',
        ], $overrides);
    }

    // ── AC: success outcome + runnable evidence → success record ────────────

    public function test_success_outcome_with_evidence_produces_success_record(): void
    {
        $r = $this->joiner()->join($this->spec(), [
            'status' => 'success',
            'commit_sha' => 'abc123',
            'evidence' => ['tests_or_gates_result' => 'pass', 'implementation_notes' => 'done'],
        ]);

        $this->assertSame('success', $r['status']);
        $this->assertTrue($r['success']);
        $this->assertArrayHasKey('evidence_strength', $r);
        $this->assertGreaterThan(0.0, $r['evidence_strength']);
        $this->assertArrayHasKey('task_shape', $r);
        $this->assertSame('cortex', $r['task_shape']['task_family']);
    }

    public function test_evidence_strength_reflects_full_evidence_set(): void
    {
        $r = $this->joiner()->join($this->spec(), [
            'status' => 'success',
            'commit_sha' => 'abc123',
            'evidence' => ['tests_or_gates_result' => 'pass', 'implementation_notes' => 'done'],
        ]);

        $this->assertSame(1.0, $r['evidence_strength']);
    }

    public function test_evidence_strength_partial_when_only_some_present(): void
    {
        $r = $this->joiner()->join($this->spec(), [
            'status' => 'success',
            'evidence' => ['tests_or_gates_result' => 'pass'],
        ]);

        $this->assertLessThan(1.0, $r['evidence_strength']);
        $this->assertGreaterThan(0.0, $r['evidence_strength']);
    }

    // ── AC: give_back outcome preserves root_cause_hint + repair_candidate ──

    public function test_give_back_with_spec_shape_root_cause_marks_repair_candidate(): void
    {
        $r = $this->joiner()->join($this->spec(), [
            'status' => 'give_back',
            'give_back_reason' => 'worker could not proceed',
            'root_cause_hint' => 'acceptance_criteria contradictory',
        ]);

        $this->assertSame('give_back', $r['status']);
        $this->assertFalse($r['success']);
        $this->assertSame('acceptance_criteria contradictory', $r['root_cause_hint']);
        $this->assertTrue($r['repair_candidate']);
    }

    public function test_give_back_with_worker_error_root_cause_is_not_repair_candidate(): void
    {
        $r = $this->joiner()->join($this->spec(), [
            'status' => 'give_back',
            'give_back_reason' => 'worker timed out',
            'root_cause_hint' => 'transient network failure',
        ]);

        $this->assertFalse($r['repair_candidate']);
    }

    public function test_give_back_reason_alone_can_trigger_repair_candidate(): void
    {
        $r = $this->joiner()->join($this->spec(), [
            'status' => 'give_back',
            'give_back_reason' => 'missing_test_path in allowed_files',
        ]);

        $this->assertTrue($r['repair_candidate']);
    }

    // ── AC: missing outcome data → pending_outcome ───────────────────────────

    public function test_missing_status_returns_pending_outcome(): void
    {
        $r = $this->joiner()->join($this->spec(), []);

        $this->assertSame('pending_outcome', $r['status']);
        $this->assertArrayNotHasKey('success', $r);
        $this->assertSame('cortex', $r['task_family']);
    }

    public function test_null_status_returns_pending_outcome(): void
    {
        $r = $this->joiner()->join($this->spec(), ['status' => null]);
        $this->assertSame('pending_outcome', $r['status']);
    }

    public function test_unrecognized_status_returns_pending_not_success_or_failure(): void
    {
        $r = $this->joiner()->join($this->spec(), ['status' => 'unknown_status']);
        $this->assertSame('pending_outcome', $r['status']);
    }

    // ── task_shape preservation ───────────────────────────────────────────────

    public function test_task_shape_reports_allowed_files_count_and_test_presence(): void
    {
        $r = $this->joiner()->join($this->spec(), ['status' => 'success']);

        $this->assertSame(2, $r['task_shape']['allowed_files_count']);
        $this->assertTrue($r['task_shape']['has_test_file']);
        $this->assertSame(1, $r['task_shape']['acceptance_criteria_count']);
    }

    public function test_task_shape_detects_no_test_file(): void
    {
        $r = $this->joiner()->join($this->spec(['allowed_files' => ['app/Services/Foo.php']]), ['status' => 'success']);
        $this->assertFalse($r['task_shape']['has_test_file']);
    }

    // ── worker/model/decision_changed preservation ────────────────────────────

    public function test_worker_and_model_preserved_on_success(): void
    {
        $r = $this->joiner()->join($this->spec(), ['status' => 'success']);
        $this->assertSame('claude-muscle-1', $r['worker']);
        $this->assertSame('sonnet', $r['model']);
    }

    public function test_decision_changed_flag_preserved(): void
    {
        $r = $this->joiner()->join($this->spec(), ['status' => 'success', 'decision_changed' => true]);
        $this->assertTrue($r['decision_changed']);
    }

    public function test_outcome_worker_overrides_spec_worker(): void
    {
        $r = $this->joiner()->join($this->spec(), ['status' => 'success', 'worker' => 'claude-muscle-9']);
        $this->assertSame('claude-muscle-9', $r['worker']);
    }

    // ── determinism + purity ──────────────────────────────────────────────────

    public function test_join_is_deterministic(): void
    {
        $spec = $this->spec();
        $outcome = ['status' => 'success', 'evidence' => ['tests_or_gates_result' => 'pass']];

        $a = $this->joiner()->join($spec, $outcome);
        $b = $this->joiner()->join($spec, $outcome);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_joiner_source_has_no_io_calls(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainSpecOutcomeTraceJoiner.php');
        foreach (['file_get_contents', 'file_put_contents', 'exec(', 'shell_exec', 'Process::', 'DB::', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "joiner must not call {$forbidden}");
        }
    }

    // ── weak_green / poison / failed_gate + learning_signal ──────────────────

    public function test_weak_green_outcome_has_required_fields(): void
    {
        $result = $this->joiner()->join($this->spec(), ['status' => 'weak_green']);

        foreach (['status', 'success', 'task_shape', 'worker', 'model', 'decision_changed', 'evidence_strength', 'learning_signal'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainSpecOutcomeTraceJoiner::STATUS_WEAK_GREEN, $result['status']);
        $this->assertFalse($result['success']);
    }

    public function test_poison_outcome_has_required_fields(): void
    {
        $result = $this->joiner()->join($this->spec(), ['status' => 'poison']);

        foreach (['status', 'success', 'task_shape', 'worker', 'model', 'decision_changed', 'evidence_strength', 'learning_signal'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainSpecOutcomeTraceJoiner::STATUS_POISON, $result['status']);
        $this->assertFalse($result['success']);
    }

    public function test_failed_gate_outcome_has_required_fields(): void
    {
        $result = $this->joiner()->join($this->spec(), ['status' => 'failed_gate']);

        foreach (['status', 'success', 'task_shape', 'worker', 'model', 'decision_changed', 'evidence_strength', 'learning_signal'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainSpecOutcomeTraceJoiner::STATUS_FAILED_GATE, $result['status']);
        $this->assertFalse($result['success']);
    }

    public function test_success_outcome_has_learning_signal(): void
    {
        $result = $this->joiner()->join($this->spec(), ['status' => 'success']);

        $this->assertArrayHasKey('learning_signal', $result);
        $this->assertNotEmpty($result['learning_signal']);
    }

    public function test_give_back_repair_candidate_learning_signal_differs_from_worker_error(): void
    {
        $specDefect = $this->joiner()->join($this->spec(), ['status' => 'give_back', 'root_cause_hint' => 'scope_too_broad']);
        $workerError = $this->joiner()->join($this->spec(), ['status' => 'give_back', 'root_cause_hint' => 'worker_timeout']);

        $this->assertTrue($specDefect['repair_candidate']);
        $this->assertFalse($workerError['repair_candidate']);
        $this->assertNotSame($specDefect['learning_signal'], $workerError['learning_signal']);
    }
}
