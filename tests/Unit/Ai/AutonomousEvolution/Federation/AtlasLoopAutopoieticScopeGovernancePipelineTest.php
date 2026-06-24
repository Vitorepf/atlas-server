<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Federation;

use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopAutopoieticScopeGovernancePipeline;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasLoopAutopoieticScopeGovernancePipeline is fail-closed: a scope rooted in the loop core is
 * rejected (ScopeOverlapsLoopCore), `admitted` is NEVER true without an operator receipt, and the verdict is
 * deterministic + write-free.
 */
final class AtlasLoopAutopoieticScopeGovernancePipelineTest extends TestCase
{
    private function pipeline(): AtlasLoopAutopoieticScopeGovernancePipeline
    {
        return new AtlasLoopAutopoieticScopeGovernancePipeline;
    }

    /** @return array<string,mixed> */
    private function validProposal(string $root): array
    {
        return [
            'scope_id' => 'finance-trading',
            'namespace' => 'FinanceTrading',
            'operator_intent' => ['rationale' => 'cover trading desk', 'scope_id' => 'finance-trading'],
            'root' => $root,
            'operator_receipt' => ['receipt_id' => 'op-123', 'signed_at' => '2026-06-24T00:00:00Z'],
        ];
    }

    public function test_scope_rooted_in_loop_core_is_rejected(): void
    {
        $result = $this->pipeline()->evaluate($this->validProposal('app/Services/Ai/AutonomousEvolution/Federation'));

        $this->assertFalse($result['admitted']);
        $this->assertContains('ScopeOverlapsLoopCore', $result['blocking_reasons'], 'a scope inside the loop core is refused');
    }

    public function test_exact_loop_core_root_also_rejected(): void
    {
        $result = $this->pipeline()->evaluate($this->validProposal('app/Services/Ai/AutonomousEvolution'));
        $this->assertContains('ScopeOverlapsLoopCore', $result['blocking_reasons']);
        $this->assertFalse($result['admitted']);
    }

    public function test_admitted_requires_an_operator_receipt(): void
    {
        // A clean, complete proposal OUTSIDE the loop core WITH a receipt ⇒ admitted.
        $admitted = $this->pipeline()->evaluate($this->validProposal('app/Services/Ai/FinanceTrading'));
        $this->assertSame([], $admitted['blocking_reasons']);
        $this->assertTrue($admitted['admitted']);
        $this->assertTrue($admitted['requires_operator_receipt'], 'admitted ⇒ requires_operator_receipt is true');

        // Same proposal WITHOUT a receipt ⇒ never admitted.
        $noReceipt = $this->validProposal('app/Services/Ai/FinanceTrading');
        unset($noReceipt['operator_receipt']);
        $blocked = $this->pipeline()->evaluate($noReceipt);
        $this->assertFalse($blocked['admitted']);
        $this->assertContains('OperatorReceiptRequired', $blocked['blocking_reasons']);

        // INVARIANT: requires_operator_receipt is always true, so admitted ⇒ requires_operator_receipt.
        $this->assertTrue($blocked['requires_operator_receipt']);
    }

    public function test_deterministic(): void
    {
        $proposal = $this->validProposal('app/Services/Ai/FinanceTrading');
        $run1 = $this->pipeline()->evaluate($proposal);
        $run2 = $this->pipeline()->evaluate($proposal);

        $this->assertSame(json_encode($run1), json_encode($run2));
    }
}
