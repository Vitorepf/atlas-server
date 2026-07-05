<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Cortex;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexMalformedSweepDigest;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCortexMalformedSweepDigestTest extends TestCase
{
    private AtlasSelfConstructionCortexMalformedSweepDigest $digest;

    protected function setUp(): void
    {
        $this->digest = new AtlasSelfConstructionCortexMalformedSweepDigest;
    }

    public function test_zero_block_count_emits_clean_proof(): void
    {
        $result = $this->digest->digest([]);

        $this->assertSame(0, $result['would_block_count']);
        $this->assertTrue($result['clean_proof']);
        $this->assertFalse($result['repair_required']);
    }

    public function test_nonzero_blockers_emit_repair_facts(): void
    {
        $result = $this->digest->digest([
            ['task_id' => 't1', 'reason' => 'missing_scope'],
            ['task_id' => 't2', 'reason' => 'poison_risk'],
        ]);

        $this->assertSame(2, $result['would_block_count']);
        $this->assertFalse($result['clean_proof']);
        $this->assertTrue($result['repair_required']);
        $this->assertCount(2, $result['repair_facts']);
    }

    public function test_reason_summaries_present(): void
    {
        $result = $this->digest->digest([
            ['task_id' => 't1', 'reason' => 'missing_scope'],
            ['task_id' => 't2', 'reason' => 'poison_risk'],
            ['task_id' => 't3', 'reason' => 'missing_scope'],
        ]);

        $this->assertContains('missing_scope', $result['reason_summaries']);
        $this->assertContains('poison_risk', $result['reason_summaries']);
        $this->assertCount(2, $result['reason_summaries']);
    }

    public function test_schema_present(): void
    {
        $result = $this->digest->digest([]);
        $this->assertSame(AtlasSelfConstructionCortexMalformedSweepDigest::SCHEMA, $result['schema']);
    }
}
