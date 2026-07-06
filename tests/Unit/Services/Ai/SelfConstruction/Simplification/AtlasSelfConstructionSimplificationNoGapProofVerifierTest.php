<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationNoGapProofVerifier;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationNoGapProofVerifierTest extends TestCase
{
    private AtlasSelfConstructionSimplificationNoGapProofVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new AtlasSelfConstructionSimplificationNoGapProofVerifier;
    }

    public function test_missing_downstream_proof_blocks_simplification(): void
    {
        $result = $this->verifier->verify([
            'downstream_proof_preserved' => false,
            'learning_capability_preserved' => true,
            'recovery_capability_preserved' => true,
        ]);

        $this->assertFalse($result['verified']);
        $this->assertContains('missing_downstream_proof', $result['blockers']);
    }

    public function test_missing_learning_capability_blocks(): void
    {
        $result = $this->verifier->verify([
            'downstream_proof_preserved' => true,
            'learning_capability_preserved' => false,
            'recovery_capability_preserved' => true,
        ]);

        $this->assertFalse($result['verified']);
        $this->assertContains('missing_learning_capability', $result['blockers']);
    }

    public function test_missing_recovery_capability_blocks(): void
    {
        $result = $this->verifier->verify([
            'downstream_proof_preserved' => true,
            'learning_capability_preserved' => true,
            'recovery_capability_preserved' => false,
        ]);

        $this->assertFalse($result['verified']);
        $this->assertContains('missing_recovery_capability', $result['blockers']);
    }

    public function test_full_preservation_proof_passes(): void
    {
        $result = $this->verifier->verify([
            'downstream_proof_preserved' => true,
            'learning_capability_preserved' => true,
            'recovery_capability_preserved' => true,
        ]);

        $this->assertTrue($result['verified']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_empty_input_blocks(): void
    {
        $result = $this->verifier->verify([]);

        $this->assertFalse($result['verified']);
        $this->assertCount(3, $result['blockers']);
    }

    public function test_schema_present(): void
    {
        $result = $this->verifier->verify([]);
        $this->assertSame(AtlasSelfConstructionSimplificationNoGapProofVerifier::SCHEMA, $result['schema']);
    }
}
