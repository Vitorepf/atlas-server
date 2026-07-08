<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiAgentLoopCertificationHasher;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiAgentLoopCertificationService;
use Tests\TestCase;

/**
 * Locks the contract of AgentControlPlaneMultiAgentLoopCertificationHasher —
 * the JSON / serialization + canonical-hash helper concern extracted from
 * the god-class AgentControlPlaneMultiAgentLoopCertificationService.
 */
final class AtlasAiSelfConstructionAgentControlPlaneMultiAgentLoopCertificationHasherTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $section = new AgentControlPlaneMultiAgentLoopCertificationHasher();
        $this->assertInstanceOf(AgentControlPlaneMultiAgentLoopCertificationHasher::class, $section);
    }

    public function test_all_4_hasher_methods_exist_on_section(): void
    {
        $section = new AgentControlPlaneMultiAgentLoopCertificationHasher();

        $ref = new \ReflectionClass($section);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => in_array($m->getName(), [
                'loadJson',
                'encodeJson',
                'normalizeForHash',
                'stableHash',
            ], true)
        );
        $this->assertCount(
            4,
            $publicMethods,
            'Section must expose all 4 hasher helpers as public methods'
        );
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AgentControlPlaneMultiAgentLoopCertificationService::class);
        foreach ([
            'loadJson',
            'encodeJson',
            'normalizeForHash',
            'stableHash',
        ] as $method) {
            $this->assertTrue(
                method_exists($runtime, $method),
                "AgentControlPlaneMultiAgentLoopCertificationService::{$method} must exist as a delegator"
            );
        }
    }

    public function test_section_can_be_resolved_via_runtime_lazy_resolver(): void
    {
        $runtime = app(AgentControlPlaneMultiAgentLoopCertificationService::class);
        $ref = new \ReflectionMethod($runtime, 'hasher');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(AgentControlPlaneMultiAgentLoopCertificationHasher::class, $section);
    }

    public function test_stable_hash_is_byte_identical_across_two_runs(): void
    {
        $section = new AgentControlPlaneMultiAgentLoopCertificationHasher();
        $payload = ['b' => 1, 'a' => 2, 'nested' => ['y' => 3, 'x' => 4]];

        $hash1 = $section->stableHash($payload);
        $hash2 = $section->stableHash($payload);

        $this->assertSame($hash1, $hash2, 'stableHash must be deterministic for identical input');
    }

    // ── evidenceIdentityHash / classifyEvidence ────────────────────────────────

    private function evidence(array $overrides = []): array
    {
        return array_merge([
            'task_id' => 'codex-meta-task-1',
            'worker_id' => 'worker-vipvtpwy',
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'proof_command' => 'php artisan test --filter=FooTest',
            'outcome_class' => 'success',
        ], $overrides);
    }

    public function test_identity_hash_is_stable_sha256(): void
    {
        $hasher = new AgentControlPlaneMultiAgentLoopCertificationHasher();
        $hash = $hasher->evidenceIdentityHash($this->evidence());

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        $this->assertSame($hash, $hasher->evidenceIdentityHash($this->evidence()));
    }

    public function test_identity_hash_ignores_allowed_files_ordering(): void
    {
        $hasher = new AgentControlPlaneMultiAgentLoopCertificationHasher();

        $a = $hasher->evidenceIdentityHash($this->evidence(['allowed_files' => ['app/Foo.php', 'tests/FooTest.php']]));
        $b = $hasher->evidenceIdentityHash($this->evidence(['allowed_files' => ['tests/FooTest.php', 'app/Foo.php']]));

        $this->assertSame($a, $b, 'allowed_files ordering must not change the identity hash');
    }

    public function test_identity_hash_changes_when_meaningful_field_changes(): void
    {
        $hasher = new AgentControlPlaneMultiAgentLoopCertificationHasher();

        $base = $hasher->evidenceIdentityHash($this->evidence());
        $differentTask = $hasher->evidenceIdentityHash($this->evidence(['task_id' => 'codex-meta-task-2']));
        $differentWorker = $hasher->evidenceIdentityHash($this->evidence(['worker_id' => 'worker-other']));
        $differentProof = $hasher->evidenceIdentityHash($this->evidence(['proof_command' => 'php artisan test --filter=BarTest']));
        $differentOutcome = $hasher->evidenceIdentityHash($this->evidence(['outcome_class' => 'failed']));

        $this->assertNotSame($base, $differentTask);
        $this->assertNotSame($base, $differentWorker);
        $this->assertNotSame($base, $differentProof);
        $this->assertNotSame($base, $differentOutcome);
    }

    public function test_classify_evidence_flags_duplicate_when_hash_already_seen(): void
    {
        $hasher = new AgentControlPlaneMultiAgentLoopCertificationHasher();
        $evidence = $this->evidence();
        $hash = $hasher->evidenceIdentityHash($evidence);

        $result = $hasher->classifyEvidence($evidence, [$hash]);

        $this->assertSame($hash, $result['identity_hash']);
        $this->assertTrue($result['duplicate_evidence']);
        $this->assertFalse($result['tamper_suspected']);
    }

    public function test_classify_evidence_no_duplicate_when_hash_not_seen(): void
    {
        $hasher = new AgentControlPlaneMultiAgentLoopCertificationHasher();
        $result = $hasher->classifyEvidence($this->evidence(), ['some-other-hash']);

        $this->assertFalse($result['duplicate_evidence']);
    }

    public function test_classify_evidence_flags_tamper_when_expected_hash_mismatches(): void
    {
        $hasher = new AgentControlPlaneMultiAgentLoopCertificationHasher();
        $evidence = $this->evidence(['expected_identity_hash' => 'deadbeef']);

        $result = $hasher->classifyEvidence($evidence);

        $this->assertTrue($result['tamper_suspected']);
    }

    public function test_classify_evidence_no_tamper_when_expected_hash_matches(): void
    {
        $hasher = new AgentControlPlaneMultiAgentLoopCertificationHasher();
        $evidence = $this->evidence();
        $hash = $hasher->evidenceIdentityHash($evidence);

        $result = $hasher->classifyEvidence($evidence + ['expected_identity_hash' => $hash]);

        $this->assertFalse($result['tamper_suspected']);
    }

    public function test_classify_evidence_no_tamper_when_expected_hash_absent(): void
    {
        $hasher = new AgentControlPlaneMultiAgentLoopCertificationHasher();
        $result = $hasher->classifyEvidence($this->evidence());

        $this->assertFalse($result['tamper_suspected']);
    }
}