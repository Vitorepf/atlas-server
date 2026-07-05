<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasTaskGraphSeedBatchChainVerifier;
use PHPUnit\Framework\TestCase;

final class AtlasTaskGraphSeedBatchChainVerifierTest extends TestCase
{
    private AtlasTaskGraphSeedBatchChainVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new AtlasTaskGraphSeedBatchChainVerifier;
    }

    public function test_coherent_chain_passes(): void
    {
        $result = $this->verifier->verify([
            ['id' => 's1', 'chain_action' => 'unlock', 'capability_chain' => 'brain'],
            ['id' => 's2', 'chain_action' => 'verify', 'capability_chain' => 'brain'],
            ['id' => 's3', 'chain_action' => 'repair', 'capability_chain' => 'brain'],
        ]);

        $this->assertTrue($result['coherent']);
        $this->assertSame(3, $result['valid_count']);
    }

    public function test_unrelated_padding_rejected(): void
    {
        $result = $this->verifier->verify([
            ['id' => 's1', 'chain_action' => 'unlock', 'capability_chain' => 'brain'],
            ['id' => 's2', 'chain_action' => 'unlock', 'capability_chain' => 'maestro'],
        ]);

        $this->assertFalse($result['coherent']);
        $this->assertSame(2, $result['chain_count']);
    }

    public function test_invalid_action_rejected(): void
    {
        $result = $this->verifier->verify([
            ['id' => 's1', 'chain_action' => 'pad', 'capability_chain' => 'brain'],
        ]);

        $this->assertFalse($result['coherent']);
        $this->assertSame(1, $result['invalid_count']);
    }

    public function test_missing_action_rejected(): void
    {
        $result = $this->verifier->verify([
            ['id' => 's1', 'capability_chain' => 'brain'],
        ]);

        $this->assertFalse($result['coherent']);
        $this->assertSame(1, $result['invalid_count']);
    }

    public function test_empty_batch_not_coherent(): void
    {
        $result = $this->verifier->verify([]);
        $this->assertFalse($result['coherent']);
    }

    public function test_schema_present(): void
    {
        $result = $this->verifier->verify([]);
        $this->assertSame(AtlasTaskGraphSeedBatchChainVerifier::SCHEMA, $result['schema']);
    }
}
