<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\MemoryIntegration;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration\AtlasCortexMemoryWriteProposalGate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtlasCortexMemoryWriteProposalGateTest extends TestCase
{
    #[Test]
    public function it_rejects_without_an_explicit_operator_approval_token(): void
    {
        $gate = new AtlasCortexMemoryWriteProposalGate;

        $result = $gate->evaluate($this->proposal(), null);

        self::assertFalse($result['accepted']);
        self::assertSame('missing_operator_approval', $result['reason']);
        self::assertNull($result['write_ticket']);
    }

    #[Test]
    public function it_rejects_ungrounded_proposals_even_with_an_approval_token(): void
    {
        $gate = new AtlasCortexMemoryWriteProposalGate;

        $result = $gate->evaluate($this->proposal([
            'grounding' => [
                'status' => 'ungrounded',
                'inventory_items' => [],
            ],
        ]), 'operator-ok');

        self::assertFalse($result['accepted']);
        self::assertSame('ungrounded', $result['reason']);
    }

    #[Test]
    public function it_returns_a_write_ticket_and_decision_record_for_approved_grounded_non_forbidden_proposals(): void
    {
        $gate = new AtlasCortexMemoryWriteProposalGate;

        $result = $gate->evaluate($this->proposal(), 'operator-ok');

        self::assertTrue($result['accepted']);
        self::assertSame('mem-1', $result['write_ticket']['target_memory_entry_id']);
        self::assertSame(['fact' => 'grounded change'], $result['write_ticket']['fact_payload']);
        self::assertSame('approved', $result['decision_record']['decision']);
        self::assertSame('mem-1', $result['decision_record']['target_memory_entry_id']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function proposal(array $overrides = []): array
    {
        return array_merge([
            'memory_entry_id' => 'mem-1',
            'fact_payload' => ['fact' => 'grounded change'],
            'grounding' => [
                'status' => 'grounded',
                'inventory_items' => [['id' => 'inv-1']],
            ],
            'privacy_class' => 'normal',
        ], $overrides);
    }
}
