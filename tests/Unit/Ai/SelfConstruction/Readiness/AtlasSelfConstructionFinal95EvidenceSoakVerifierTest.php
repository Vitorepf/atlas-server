<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionFinal95EvidenceSoakVerifier;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionFinal95EvidenceSoakVerifierTest extends TestCase
{
    private AtlasSelfConstructionFinal95EvidenceSoakVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new AtlasSelfConstructionFinal95EvidenceSoakVerifier();
    }

    // AC: fails with insufficient_worker_diversity when narrow
    public function test_single_worker_fails_worker_diversity(): void
    {
        $result = $this->verifier->verify([
            ['worker_id' => 'w1', 'task_family' => 'Brain', 'outcome' => 'success'],
            ['worker_id' => 'w1', 'task_family' => 'Cortex', 'outcome' => 'success'],
        ]);

        $this->assertFalse($result['ready']);
        $this->assertTrue(
            count(array_filter($result['blockers'], fn ($b) => str_contains($b, 'insufficient_worker_diversity'))) > 0
        );
    }

    public function test_single_family_fails_family_diversity(): void
    {
        $result = $this->verifier->verify([
            ['worker_id' => 'w1', 'task_family' => 'Brain', 'outcome' => 'success'],
            ['worker_id' => 'w2', 'task_family' => 'Brain', 'outcome' => 'success'],
        ]);

        $this->assertFalse($result['ready']);
        $this->assertTrue(
            count(array_filter($result['blockers'], fn ($b) => str_contains($b, 'insufficient_task_family_diversity'))) > 0
        );
    }

    public function test_diverse_workers_and_families_passes(): void
    {
        $result = $this->verifier->verify([
            ['worker_id' => 'w1', 'task_family' => 'Brain', 'outcome' => 'success'],
            ['worker_id' => 'w2', 'task_family' => 'Cortex', 'outcome' => 'success'],
        ]);

        $this->assertTrue($result['ready']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_empty_samples_fails(): void
    {
        $result = $this->verifier->verify([]);

        $this->assertFalse($result['ready']);
        $this->assertCount(2, $result['blockers']);
    }
}
