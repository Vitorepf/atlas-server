<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionSteadyStateProofCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSteadyStateProofCompilerTest extends TestCase
{
    private AtlasSelfConstructionSteadyStateProofCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new AtlasSelfConstructionSteadyStateProofCompiler;
    }

    public function test_all_proofs_present_is_ready(): void
    {
        $result = $this->compiler->compile([
            'queue_steady_state_proof' => 'receipt:q1',
            'learning_steady_state_proof' => 'receipt:l1',
            'recovery_steady_state_proof' => 'receipt:r1',
            'originator_steady_state_proof' => 'receipt:o1',
            'verification_steady_state_proof' => 'receipt:v1',
        ]);

        $this->assertTrue($result['ready']);
        $this->assertEmpty($result['missing_proofs']);
    }

    public function test_missing_queue_proof_blocks_with_exact_id(): void
    {
        $result = $this->compiler->compile([
            'learning_steady_state_proof' => 'receipt:l1',
            'recovery_steady_state_proof' => 'receipt:r1',
            'originator_steady_state_proof' => 'receipt:o1',
            'verification_steady_state_proof' => 'receipt:v1',
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('queue_steady_state_proof', $result['missing_proofs']);
    }

    public function test_missing_learning_proof_blocks_with_exact_id(): void
    {
        $result = $this->compiler->compile([
            'queue_steady_state_proof' => 'receipt:q1',
            'recovery_steady_state_proof' => 'receipt:r1',
            'originator_steady_state_proof' => 'receipt:o1',
            'verification_steady_state_proof' => 'receipt:v1',
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('learning_steady_state_proof', $result['missing_proofs']);
    }

    public function test_missing_recovery_proof_blocks_with_exact_id(): void
    {
        $result = $this->compiler->compile([
            'queue_steady_state_proof' => 'receipt:q1',
            'learning_steady_state_proof' => 'receipt:l1',
            'originator_steady_state_proof' => 'receipt:o1',
            'verification_steady_state_proof' => 'receipt:v1',
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('recovery_steady_state_proof', $result['missing_proofs']);
    }

    public function test_empty_input_blocks_all_proofs(): void
    {
        $result = $this->compiler->compile([]);

        $this->assertFalse($result['ready']);
        $this->assertSame(5, $result['missing_proof_count']);
    }

    public function test_schema_present(): void
    {
        $result = $this->compiler->compile([]);
        $this->assertSame(AtlasSelfConstructionSteadyStateProofCompiler::SCHEMA, $result['schema']);
    }
}
