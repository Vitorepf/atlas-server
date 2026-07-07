<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TaskOutcomeLearningCandidate;
use Tests\TestCase;

/**
 * C3 (Obra #18) — the delta-surprise filter that decides whether an esteira outcome
 * becomes a G0 memory candidate: only a PROVEN completion (a success that landed a
 * commit/evidence) does; a bare success or a failure produces nothing.
 */
final class TaskOutcomeLearningCandidateTest extends TestCase
{
    public function test_proven_landed_success_becomes_a_memory_candidate(): void
    {
        $candidate = TaskOutcomeLearningCandidate::from(
            ['objective' => 'wire AtlasFooService into the kernel', 'allowed_files' => ['app/Services/Ai/Foo.php']],
            ['outcome' => 'success', 'commit' => 'abc123def456'],
        );

        $this->assertNotNull($candidate);
        $this->assertSame('memory', $candidate['kind']);
        $this->assertStringContainsString('AtlasFooService', $candidate['summary']);
        $this->assertContains('commit:abc123def456', $candidate['evidence_refs']);
        $this->assertContains('app/Services/Ai/Foo.php', $candidate['evidence_refs']);
    }

    public function test_bare_success_without_landed_proof_is_filtered(): void
    {
        $this->assertNull(TaskOutcomeLearningCandidate::from(
            ['objective' => 'did something', 'allowed_files' => ['app/X.php']],
            ['outcome' => 'success'], // no commit, no evidence ⇒ not novel
        ));
    }

    public function test_a_failure_never_memorialises(): void
    {
        $this->assertNull(TaskOutcomeLearningCandidate::from(
            ['objective' => 'tried and failed', 'allowed_files' => ['app/X.php']],
            ['outcome' => 'failed', 'commit' => 'abc123'],
        ));
    }

    public function test_no_objective_produces_no_candidate(): void
    {
        $this->assertNull(TaskOutcomeLearningCandidate::from(
            ['objective' => '', 'allowed_files' => ['app/X.php']],
            ['outcome' => 'success', 'commit' => 'abc123'],
        ));
    }
}
