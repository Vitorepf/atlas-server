<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\WorkerSwarm;

use App\Services\Ai\SelfConstruction\WorkerSwarm\AtlasSelfConstructionWorkerResultNormalizer;
use Tests\TestCase;

final class AtlasSelfConstructionWorkerResultNormalizerTest extends TestCase
{
    private function task(array $overrides = []): array
    {
        return $overrides + [
            'task_id' => 'pkt-1',
            'lease_id' => 'lease-1',
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'required_evidence_kinds' => ['phpunit', 'mutop'],
        ];
    }

    public function test_success_with_full_evidence_and_green_gates_is_verified_pass(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome' => 'success',
            'changed_files' => ['app/Foo.php'],
            'gate_outputs' => ['phpunit' => ['passed' => true], 'lint' => true],
            'evidence_refs' => ['phpunit:test_foo', 'mutop:fooKill'],
        ]);

        $this->assertSame(AtlasSelfConstructionWorkerResultNormalizer::STATUS_VERIFIED_PASS, $verdict['court_status']);
        $this->assertSame('success', $verdict['claimed_outcome']);
        $this->assertSame(['phpunit:test_foo', 'mutop:fooKill'], $verdict['verified_evidence']);
        $this->assertSame([], $verdict['unverified_claims']);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_give_back_with_missing_evidence_is_pending_review(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome' => 'give_back',
            'changed_files' => [],
            'gate_outputs' => [],
            'evidence_refs' => [],
        ]);

        $this->assertSame(AtlasSelfConstructionWorkerResultNormalizer::STATUS_PENDING_REVIEW, $verdict['court_status']);
        $this->assertContains('missing_evidence_for:phpunit,mutop', $verdict['unverified_claims']);
    }

    public function test_scope_violation_blocks(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome' => 'success',
            'changed_files' => ['app/Foo.php', 'config/atlas.php'],
            'gate_outputs' => ['phpunit' => ['passed' => true]],
            'evidence_refs' => ['phpunit:t1', 'mutop:m1'],
        ]);

        $this->assertSame(AtlasSelfConstructionWorkerResultNormalizer::STATUS_BLOCKED, $verdict['court_status']);
        $this->assertContains('config/atlas.php', $verdict['out_of_scope_files']);
        $this->assertStringContainsString('changed_files_outside_allowed_scope', $verdict['blockers'][0]);
    }

    public function test_failed_gate_yields_verified_fail(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome' => 'success',
            'changed_files' => ['app/Foo.php'],
            'gate_outputs' => ['phpunit' => ['passed' => false]],
            'evidence_refs' => ['phpunit:t1', 'mutop:m1'],
        ]);

        $this->assertSame(AtlasSelfConstructionWorkerResultNormalizer::STATUS_VERIFIED_FAIL, $verdict['court_status']);
        $this->assertContains('gates_failed:phpunit', $verdict['blockers']);
        $this->assertContains('success', $verdict['unverified_claims']);
    }

    public function test_claimed_outcome_is_separated_from_verified_evidence(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome' => 'success', // claim
            'changed_files' => ['app/Foo.php'],
            'gate_outputs' => [],            // no gates ran
            'evidence_refs' => [],            // no evidence
        ]);

        // The claim is recorded but NOT marked verified.
        $this->assertSame('success', $verdict['claimed_outcome']);
        $this->assertSame(AtlasSelfConstructionWorkerResultNormalizer::STATUS_PENDING_REVIEW, $verdict['court_status']);
        $this->assertSame([], $verdict['verified_evidence']);
    }

    // ── worker_quality_facts ──────────────────────────────────────────────────

    public function test_worker_quality_facts_are_included_in_output(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome'  => 'success',
            'changed_files'    => ['app/Foo.php'],
            'gate_outputs'     => ['phpunit' => ['passed' => true]],
            'evidence_refs'    => ['phpunit:t1', 'mutop:m1'],
            'worker_client_id' => 'claude-muscle-4',
            'engine_kind'      => 'claude-sonnet-4-6',
            'task_shape'       => 'feature',
            'elapsed_seconds'  => 47.3,
            'outcome_kind'     => 'success',
            'give_back_reason' => '',
        ]);

        $qf = $verdict['worker_quality_facts'];
        $this->assertSame('claude-muscle-4', $qf['worker_client_id']);
        $this->assertSame('claude-sonnet-4-6', $qf['engine_kind']);
        $this->assertSame('feature', $qf['task_shape']);
        $this->assertSame(47.3, $qf['elapsed_seconds']);
        $this->assertSame('success', $qf['outcome_kind']);
        $this->assertSame('', $qf['give_back_reason']);
    }

    public function test_worker_quality_facts_absent_fields_default_to_empty(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome' => 'success',
            'changed_files'   => ['app/Foo.php'],
            'gate_outputs'    => ['phpunit' => true, 'mutop' => true],
            'evidence_refs'   => ['phpunit:t1', 'mutop:m1'],
        ]);

        $qf = $verdict['worker_quality_facts'];
        $this->assertSame('', $qf['worker_client_id']);
        $this->assertSame('', $qf['engine_kind']);
        $this->assertNull($qf['elapsed_seconds']);
        $this->assertSame('success', $qf['outcome_kind']); // defaults to claimed_outcome
    }

    public function test_give_back_with_full_evidence_and_green_gates_is_not_verified_pass(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome'  => 'give_back',
            'changed_files'    => [],
            'gate_outputs'     => ['phpunit' => ['passed' => true], 'lint' => true],
            'evidence_refs'    => ['phpunit:diag1', 'mutop:diag2'],
            'outcome_kind'     => 'give_back',
            'give_back_reason' => 'task_too_large',
        ]);

        $this->assertSame(AtlasSelfConstructionWorkerResultNormalizer::STATUS_PENDING_REVIEW, $verdict['court_status']);
        // Evidence is preserved for the learning loop.
        $this->assertSame(['phpunit:diag1', 'mutop:diag2'], $verdict['verified_evidence']);
        $this->assertSame([], $verdict['unverified_claims']);
        $this->assertSame('give_back', $verdict['worker_quality_facts']['outcome_kind']);
        $this->assertSame('task_too_large', $verdict['worker_quality_facts']['give_back_reason']);
    }

    public function test_give_back_with_missing_evidence_carries_diagnostic_unverified_claim(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome' => 'give_back',
            'changed_files'   => [],
            'gate_outputs'    => [],
            'evidence_refs'   => ['phpunit:diag1'], // mutop missing
        ]);

        $this->assertSame(AtlasSelfConstructionWorkerResultNormalizer::STATUS_PENDING_REVIEW, $verdict['court_status']);
        $this->assertContains('missing_evidence_for:mutop', $verdict['unverified_claims']);
        $this->assertSame(['phpunit:diag1'], $verdict['verified_evidence']);
    }

    // ── AC: vague success evidence is normalized as weak_green with needs_review=true ──

    public function test_vague_success_evidence_is_weak_green_and_needs_review(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome' => 'success',
            'changed_files'   => ['app/Foo.php'],
            'gate_outputs'    => ['phpunit' => ['passed' => true], 'mutop' => ['passed' => true]],
            'evidence_refs'   => ['phpunit:done', 'mutop:ok'],
        ]);

        $this->assertSame(AtlasSelfConstructionWorkerResultNormalizer::STATUS_VERIFIED_PASS, $verdict['court_status']);
        $this->assertSame('weak_green', $verdict['result_class']);
        $this->assertSame('weak', $verdict['proof_quality']);
        $this->assertTrue($verdict['needs_review']);
    }

    // ── AC: concrete command/path evidence is verified_success with proof_quality=strong ──

    public function test_concrete_command_path_evidence_is_verified_success_with_strong_proof(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome' => 'success',
            'changed_files'   => ['app/Foo.php'],
            'gate_outputs'    => ['phpunit' => ['passed' => true], 'mutop' => ['passed' => true]],
            'evidence_refs'   => ['phpunit:./vendor/bin/phpunit tests/Unit/FooTest.php', 'mutop:app/Foo.php'],
        ]);

        $this->assertSame(AtlasSelfConstructionWorkerResultNormalizer::STATUS_VERIFIED_PASS, $verdict['court_status']);
        $this->assertSame('verified_success', $verdict['result_class']);
        $this->assertSame('strong', $verdict['proof_quality']);
        $this->assertFalse($verdict['needs_review']);
    }

    public function test_no_evidence_has_proof_quality_none(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome' => 'give_back',
            'changed_files'   => [],
            'gate_outputs'    => [],
            'evidence_refs'   => [],
        ]);

        $this->assertSame('none', $verdict['proof_quality']);
    }

    // ── AC: repeated scope or contradiction give_backs get root_cause_family, poison class ──

    public function test_scope_give_back_reason_gets_scope_root_cause_family(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome'  => 'give_back',
            'changed_files'    => [],
            'gate_outputs'     => [],
            'evidence_refs'    => [],
            'give_back_reason' => 'task scope exceeds allowed_files boundary',
        ]);

        $this->assertSame('scope', $verdict['root_cause_family']);
        $this->assertSame('give_back', $verdict['result_class']);
    }

    public function test_contradiction_give_back_reason_gets_contradiction_root_cause_family(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome'  => 'give_back',
            'changed_files'    => [],
            'gate_outputs'     => [],
            'evidence_refs'    => [],
            'give_back_reason' => 'acceptance criteria contradict each other',
        ]);

        $this->assertSame('contradiction', $verdict['root_cause_family']);
    }

    public function test_repeated_scope_give_back_is_classified_poison(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome'  => 'give_back',
            'changed_files'    => [],
            'gate_outputs'     => [],
            'evidence_refs'    => [],
            'give_back_reason' => 'task scope exceeds allowed_files boundary',
            'give_back_count'  => 4,
        ]);

        $this->assertSame('poison', $verdict['result_class']);
        $this->assertSame('scope', $verdict['root_cause_family']);
    }

    public function test_single_scope_give_back_is_not_poison(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome'  => 'give_back',
            'changed_files'    => [],
            'gate_outputs'     => [],
            'evidence_refs'    => [],
            'give_back_reason' => 'task scope exceeds allowed_files boundary',
            'give_back_count'  => 1,
        ]);

        $this->assertSame('give_back', $verdict['result_class']);
    }

    public function test_non_give_back_result_has_null_root_cause_family(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome' => 'success',
            'changed_files'   => ['app/Foo.php'],
            'gate_outputs'    => ['phpunit' => ['passed' => true], 'mutop' => ['passed' => true]],
            'evidence_refs'   => ['phpunit:app/Foo.php', 'mutop:app/Foo.php'],
        ]);

        $this->assertNull($verdict['root_cause_family']);
    }

    public function test_scope_violation_is_classified_blocked_result_class(): void
    {
        $verdict = (new AtlasSelfConstructionWorkerResultNormalizer)->normalize($this->task(), [
            'claimed_outcome' => 'success',
            'changed_files'   => ['app/Foo.php', 'config/atlas.php'],
            'gate_outputs'    => ['phpunit' => ['passed' => true]],
            'evidence_refs'   => ['phpunit:t1', 'mutop:m1'],
        ]);

        $this->assertSame('blocked', $verdict['result_class']);
    }
}
