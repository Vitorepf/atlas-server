<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Trinity;

use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\AtlasLoopTrinityFeedbackAuditor;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Trinity feedback auditor: a cycle is static when any of Loop/Cortex/Maestro produced no new
 * factId vs the prior cycle (3 per-primitive violations), non-static when all three did, and the audit result
 * is itself seeded as a TrinityFact for the next cycle (recursive closure).
 */
final class AtlasLoopTrinityFeedbackAuditorTest extends TestCase
{
    /** @return array<string,mixed> */
    private function fact(string $factId, string $source, string $cycleId): array
    {
        return ['factId' => $factId, 'source' => $source, 'cycleId' => $cycleId, 'payload' => [], 'parentFactIds' => []];
    }

    /**
     * c1 baseline {L1,C1,M1}; c2 reuses the given factIds (a reused id ⇒ that primitive is static).
     *
     * @return list<array<string,mixed>>
     */
    private function stream(string $c2Loop, string $c2Cortex, string $c2Maestro): array
    {
        return [
            $this->fact('L1', 'loop', 'c1'),
            $this->fact('C1', 'cortex', 'c1'),
            $this->fact('M1', 'maestro', 'c1'),
            $this->fact($c2Loop, 'loop', 'c2'),
            $this->fact($c2Cortex, 'cortex', 'c2'),
            $this->fact($c2Maestro, 'maestro', 'c2'),
        ];
    }

    public function test_static_when_loop_produced_no_new_fact(): void
    {
        $result = (new AtlasLoopTrinityFeedbackAuditor($this->stream('L1', 'C2', 'M2')))->audit('c2');

        $this->assertTrue($result->isStatic);
        $this->assertContains('static_cycle_violation:loop', $result->violations);
        $this->assertSame([], $result->newLoopFacts);
    }

    public function test_static_when_cortex_produced_no_new_fact(): void
    {
        $result = (new AtlasLoopTrinityFeedbackAuditor($this->stream('L2', 'C1', 'M2')))->audit('c2');

        $this->assertTrue($result->isStatic);
        $this->assertContains('static_cycle_violation:cortex', $result->violations);
    }

    public function test_static_when_maestro_produced_no_new_fact(): void
    {
        $result = (new AtlasLoopTrinityFeedbackAuditor($this->stream('L2', 'C2', 'M1')))->audit('c2');

        $this->assertTrue($result->isStatic);
        $this->assertContains('static_cycle_violation:maestro', $result->violations);
    }

    public function test_non_static_when_all_three_produced_new_facts(): void
    {
        $result = (new AtlasLoopTrinityFeedbackAuditor($this->stream('L2', 'C2', 'M2')))->audit('c2');

        $this->assertFalse($result->isStatic);
        $this->assertSame([], $result->violations);
        $this->assertNotEmpty($result->newLoopFacts);
        $this->assertNotEmpty($result->newCortexFacts);
        $this->assertNotEmpty($result->newMaestroFacts);
    }

    public function test_audit_is_persisted_as_a_fact_for_the_next_cycle_seed(): void
    {
        $auditor = new AtlasLoopTrinityFeedbackAuditor($this->stream('L2', 'C2', 'M2'));
        $auditor->audit('c2');

        $seed = $auditor->seedFacts();
        $this->assertCount(1, $seed);
        $this->assertSame('loop', $seed[0]['source'], 'the audit seeds the next Loop emission');
        $this->assertSame('c2', $seed[0]['payload']['cycle_id']);
        $this->assertFalse($seed[0]['payload']['is_static']);
    }
}
