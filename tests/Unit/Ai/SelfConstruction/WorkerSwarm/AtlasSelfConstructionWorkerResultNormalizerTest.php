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
}
