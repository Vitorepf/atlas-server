<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionCompletionAutonomyProofGapRouter;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCompletionAutonomyProofGapRouterTest extends TestCase
{
    private AtlasSelfConstructionCompletionAutonomyProofGapRouter $router;

    protected function setUp(): void
    {
        $this->router = new AtlasSelfConstructionCompletionAutonomyProofGapRouter;
    }

    public function test_queue_proof_gap_emits_scoped_task(): void
    {
        $result = $this->router->route(['queue_proof_present' => false]);

        $this->assertSame(1, $result['gap_count']);
        $this->assertSame('queue_proof_gap', $result['gap_tasks'][0]['gap']);
        $this->assertNotEmpty($result['gap_tasks'][0]['task_spec']);
    }

    public function test_learning_proof_gap_emits_scoped_task(): void
    {
        $result = $this->router->route(['learning_proof_present' => false]);

        $this->assertSame(1, $result['gap_count']);
        $this->assertSame('learning_proof_gap', $result['gap_tasks'][0]['gap']);
    }

    public function test_recovery_proof_gap_emits_scoped_task(): void
    {
        $result = $this->router->route(['recovery_proof_present' => false]);

        $this->assertSame(1, $result['gap_count']);
        $this->assertSame('recovery_proof_gap', $result['gap_tasks'][0]['gap']);
    }

    public function test_originator_proof_gap_emits_scoped_task(): void
    {
        $result = $this->router->route(['originator_proof_present' => false]);

        $this->assertSame(1, $result['gap_count']);
        $this->assertSame('originator_proof_gap', $result['gap_tasks'][0]['gap']);
    }

    public function test_all_proofs_present_is_complete(): void
    {
        $result = $this->router->route([]);

        $this->assertTrue($result['complete']);
        $this->assertSame(0, $result['gap_count']);
    }

    public function test_schema_present(): void
    {
        $result = $this->router->route([]);
        $this->assertSame(AtlasSelfConstructionCompletionAutonomyProofGapRouter::SCHEMA, $result['schema']);
    }
}
