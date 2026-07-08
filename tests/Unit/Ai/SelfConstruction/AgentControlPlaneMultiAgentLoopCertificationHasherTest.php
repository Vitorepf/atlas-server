<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiAgentLoopCertificationHasher;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneMultiAgentLoopCertificationHasherTest extends TestCase
{
    private AgentControlPlaneMultiAgentLoopCertificationHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new AgentControlPlaneMultiAgentLoopCertificationHasher;
    }

    private function evidence(array $overrides = []): array
    {
        return array_merge([
            'task_id' => 'task-001',
            'worker_id' => 'worker-1',
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'proof_command' => 'php artisan test --filter=Foo',
            'outcome_class' => 'pass',
        ], $overrides);
    }

    // ── AC2: identity hashes ignore volatile fields ──────────────────────────

    public function test_identity_hash_ignores_timestamps(): void
    {
        $base = $this->evidence();
        $withTimestamp = array_merge($base, ['timestamp' => '2026-07-05T10:00:00Z']);
        $withDifferentTimestamp = array_merge($base, ['timestamp' => '2026-07-05T20:00:00Z']);

        $this->assertSame(
            $this->hasher->evidenceIdentityHash($withTimestamp),
            $this->hasher->evidenceIdentityHash($withDifferentTimestamp),
        );
    }

    public function test_identity_hash_ignores_lease_ids(): void
    {
        $base = $this->evidence();
        $withLease1 = array_merge($base, ['lease_id' => 'lease_001']);
        $withLease2 = array_merge($base, ['lease_id' => 'lease_002']);

        $this->assertSame(
            $this->hasher->evidenceIdentityHash($withLease1),
            $this->hasher->evidenceIdentityHash($withLease2),
        );
    }

    public function test_identity_hash_ignores_ordering_only_differences_in_allowed_files(): void
    {
        $evidence1 = $this->evidence(['allowed_files' => ['app/Foo.php', 'tests/FooTest.php']]);
        $evidence2 = $this->evidence(['allowed_files' => ['tests/FooTest.php', 'app/Foo.php']]);

        $this->assertSame(
            $this->hasher->evidenceIdentityHash($evidence1),
            $this->hasher->evidenceIdentityHash($evidence2),
        );
    }

    // ── AC3: duplicated substantive evidence under different worker ids is classified duplicate_identity ──

    public function test_duplicated_evidence_under_different_worker_ids_is_duplicate_identity(): void
    {
        $evidence1 = $this->evidence(['worker_id' => 'worker-1']);
        $evidence2 = $this->evidence(['worker_id' => 'worker-2']);

        $substantiveHash1 = $this->hasher->substantiveIdentityHash($evidence1);
        $result = $this->hasher->classifyEvidence($evidence2, [], [$substantiveHash1]);

        $this->assertTrue($result['duplicate_identity']);
        $this->assertFalse($result['duplicate_evidence']);
    }

    public function test_same_worker_same_evidence_is_duplicate_evidence(): void
    {
        $evidence = $this->evidence();
        $hash = $this->hasher->evidenceIdentityHash($evidence);
        $result = $this->hasher->classifyEvidence($evidence, [$hash]);

        $this->assertTrue($result['duplicate_evidence']);
        $this->assertFalse($result['duplicate_identity']);
    }

    // ── AC4: substantively different evidence produces distinct identity hashes ──

    public function test_different_proof_command_produces_distinct_hashes(): void
    {
        $evidence1 = $this->evidence(['proof_command' => 'php artisan test --filter=Foo']);
        $evidence2 = $this->evidence(['proof_command' => 'php artisan test --filter=Bar']);

        $this->assertNotSame(
            $this->hasher->evidenceIdentityHash($evidence1),
            $this->hasher->evidenceIdentityHash($evidence2),
        );
    }

    public function test_different_allowed_files_produces_distinct_hashes(): void
    {
        $evidence1 = $this->evidence(['allowed_files' => ['app/Foo.php']]);
        $evidence2 = $this->evidence(['allowed_files' => ['app/Bar.php']]);

        $this->assertNotSame(
            $this->hasher->evidenceIdentityHash($evidence1),
            $this->hasher->evidenceIdentityHash($evidence2),
        );
    }

    public function test_different_outcome_class_produces_distinct_hashes(): void
    {
        $evidence1 = $this->evidence(['outcome_class' => 'pass']);
        $evidence2 = $this->evidence(['outcome_class' => 'fail']);

        $this->assertNotSame(
            $this->hasher->evidenceIdentityHash($evidence1),
            $this->hasher->evidenceIdentityHash($evidence2),
        );
    }

    public function test_different_task_id_produces_distinct_hashes(): void
    {
        $evidence1 = $this->evidence(['task_id' => 'task-001']);
        $evidence2 = $this->evidence(['task_id' => 'task-002']);

        $this->assertNotSame(
            $this->hasher->evidenceIdentityHash($evidence1),
            $this->hasher->evidenceIdentityHash($evidence2),
        );
    }

    // ── Additional tests ─────────────────────────────────────────────────────

    public function test_substantive_identity_hash_ignores_worker_id(): void
    {
        $evidence1 = $this->evidence(['worker_id' => 'worker-1']);
        $evidence2 = $this->evidence(['worker_id' => 'worker-2']);

        $this->assertSame(
            $this->hasher->substantiveIdentityHash($evidence1),
            $this->hasher->substantiveIdentityHash($evidence2),
        );
    }

    public function test_classify_evidence_returns_all_required_keys(): void
    {
        $result = $this->hasher->classifyEvidence($this->evidence());

        $this->assertArrayHasKey('identity_hash', $result);
        $this->assertArrayHasKey('substantive_identity_hash', $result);
        $this->assertArrayHasKey('duplicate_evidence', $result);
        $this->assertArrayHasKey('duplicate_identity', $result);
        $this->assertArrayHasKey('tamper_suspected', $result);
    }

    public function test_no_duplicates_when_seen_hashes_empty(): void
    {
        $result = $this->hasher->classifyEvidence($this->evidence(), []);

        $this->assertFalse($result['duplicate_evidence']);
        $this->assertFalse($result['duplicate_identity']);
    }
}
